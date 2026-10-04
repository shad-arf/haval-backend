<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ProductOverride;
use App\Models\User;
use App\Services\DolibarrClient;
use App\Services\DolibarrOrderPusher;
use App\Services\InvoicePdfRenderer;
use App\Services\PricingEngine;
use App\Support\SiteSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminShopController extends Controller
{
    /**
     * How many rows to pull from Dolibarr in one request before filtering
     * and sorting locally. Visibility and price depend on this app's own
     * DB (product_overrides, shop_settings), so they can't be pushed into
     * Dolibarr's sqlfilters/sortfield — filtering happens here instead,
     * over one large batch rather than per-page.
     *
     * If the real catalog ever exceeds this, results silently stop at
     * whatever Dolibarr's own default order returns first rather than
     * failing loudly. Dolibarr's REST API supports a `pagination_data=1`
     * flag that returns a real COUNT(*) (see api_products.class.php) —
     * that's the documented follow-up if this cap is ever hit.
     */
    private const ADMIN_PRODUCT_BATCH_SIZE = 300;

    private const ADMIN_PRODUCT_PAGE_SIZE = 30;

    public function __construct(
        private readonly DolibarrClient $dolibarr,
        private readonly PricingEngine $pricing,
        private readonly InvoicePdfRenderer $invoices,
        private readonly DolibarrOrderPusher $pusher,
    ) {}

    public function products(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $input = $request->validate([
            'q' => ['sometimes', 'string', 'max:80', 'regex:/^[\p{L}\p{N} _.\-]+$/u'],
            'page' => ['sometimes', 'integer', 'min:0'],
            'visibility' => ['sometimes', 'in:all,visible,hidden'],
            'stock' => ['sometimes', 'in:all,in_stock,low_stock,out_of_stock'],
            'stock_min' => ['sometimes', 'numeric', 'min:0'],
            'stock_max' => ['sometimes', 'numeric', 'min:0'],
            'sort' => ['sometimes', 'in:default,name_asc,name_desc,price_asc,price_desc,stock,stock_desc'],
        ]);
        $page = (int) ($input['page'] ?? 0);
        $visibility = $input['visibility'] ?? 'all';
        $stock = $input['stock'] ?? 'all';
        $stockMin = isset($input['stock_min']) ? (float) $input['stock_min'] : null;
        $stockMax = isset($input['stock_max']) ? (float) $input['stock_max'] : null;
        $sort = $input['sort'] ?? 'default';

        try {
            $products = $this->dolibarr->products(
                self::ADMIN_PRODUCT_BATCH_SIZE, 0,
                search: trim($input['q'] ?? ''), requireCost: false, requireStock: false,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'The ERP catalog is unavailable.'], 503);
        }

        $overrides = ProductOverride::whereIn('erp_product_id', array_column($products, 'id'))->get()->keyBy('erp_product_id');
        $rows = collect($products)->map(function (array $product) use ($overrides): array {
            $id = (int) ($product['id'] ?? 0);
            $override = $overrides->get($id);
            $cost = $this->pricing->sourceCost($product);
            $stockQuantity = $this->pricing->stockQuantity($product);
            $isHidden = $override?->is_hidden ?? false;
            $reason = $this->visibilityReason($cost, $stockQuantity, $isHidden);

            return [
                'id' => $id,
                'ref' => (string) ($product['ref'] ?? ''),
                'name' => (string) ($product['label'] ?? $product['ref'] ?? ''),
                'stock_quantity' => $stockQuantity,
                'stock_status' => $this->pricing->stockStatus($stockQuantity),
                'price' => $cost > 0 ? (int) $this->pricing->sellingPrice($this->pricing->costInUsd($cost), $override?->profit_percentage) : null,
                'is_hidden' => $isHidden,
                'profit_percentage' => $override?->profit_percentage,
                'display_name' => $override?->display_name,
                'description' => $override?->description,
                'storefront_visible' => $reason === null,
                'visibility_reason' => $reason,
            ];
        });

        $rows = $rows
            ->when($visibility !== 'all', fn ($rows) => $rows->filter(
                fn (array $row) => $visibility === 'visible' ? ! $row['is_hidden'] : $row['is_hidden']
            ))
            ->when($stock !== 'all', fn ($rows) => $rows->filter(fn (array $row) => $row['stock_status'] === $stock))
            ->when($stockMin !== null, fn ($rows) => $rows->filter(fn (array $row) => ($row['stock_quantity'] ?? 0) >= $stockMin))
            ->when($stockMax !== null, fn ($rows) => $rows->filter(fn (array $row) => ($row['stock_quantity'] ?? 0) <= $stockMax));

        $rows = match ($sort) {
            'name_asc' => $rows->sortBy(fn (array $row) => mb_strtolower($row['display_name'] ?? $row['name']), SORT_NATURAL)->values(),
            'name_desc' => $rows->sortByDesc(fn (array $row) => mb_strtolower($row['display_name'] ?? $row['name']), SORT_NATURAL)->values(),
            'price_asc' => $rows->sortBy(fn (array $row) => $row['price'] ?? PHP_INT_MAX)->values(),
            'price_desc' => $rows->sortByDesc(fn (array $row) => $row['price'] ?? PHP_INT_MIN)->values(),
            'stock' => $rows->sortBy(fn (array $row) => $row['stock_quantity'] ?? 0)->values(),
            'stock_desc' => $rows->sortByDesc(fn (array $row) => $row['stock_quantity'] ?? 0)->values(),
            default => $rows->values(),
        };

        $total = $rows->count();
        $data = $rows->slice($page * self::ADMIN_PRODUCT_PAGE_SIZE, self::ADMIN_PRODUCT_PAGE_SIZE)->values();

        return response()->json([
            'data' => $data,
            'page' => $page,
            'total' => $total,
            'has_more' => ($page + 1) * self::ADMIN_PRODUCT_PAGE_SIZE < $total,
        ]);
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'is_hidden' => ['sometimes', 'boolean'],
            'profit_percentage' => ['sometimes', 'nullable', 'numeric', 'between:0,1000'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        try {
            $product = $this->dolibarr->product($id);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'Product not found in ERP.'], 404);
        }
        $override = ProductOverride::updateOrCreate(
            ['erp_product_id' => $id],
            array_merge(['ref' => (string) ($product['ref'] ?? '')], $data),
        );

        return response()->json(['data' => $override]);
    }

    /**
     * One product's full ERP detail plus its local override, for the admin
     * product detail page reached by clicking a row in the product list.
     */
    public function showProduct(Request $request, int $id): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        try {
            $product = $this->dolibarr->product($id);
        } catch (\Throwable $exception) {
            return response()->json(['message' => 'Product not found in ERP.'], 404);
        }

        $override = ProductOverride::where('erp_product_id', $id)->first();
        $cost = $this->pricing->sourceCost($product);
        $stockQuantity = $this->pricing->stockQuantity($product);
        $isHidden = $override?->is_hidden ?? false;
        $reason = $this->visibilityReasonDetailed($id, $cost, $stockQuantity, $isHidden);
        $ref = (string) ($product['ref'] ?? '');

        return response()->json(['data' => [
            'id' => $id,
            'ref' => $ref,
            'name' => (string) ($product['label'] ?? $ref),
            'erp_description' => trim(strip_tags((string) ($product['description'] ?? ''))),
            'stock_quantity' => $stockQuantity,
            'stock_status' => $this->pricing->stockStatus($stockQuantity),
            'price' => $cost > 0 ? (int) $this->pricing->sellingPrice($this->pricing->costInUsd($cost), $override?->profit_percentage) : null,
            'has_cost' => $cost > 0,
            'is_hidden' => $isHidden,
            'profit_percentage' => $override?->profit_percentage,
            'display_name' => $override?->display_name,
            'description' => $override?->description,
            'storefront_visible' => $reason === null,
            'visibility_reason' => $reason,
            'image_url' => $ref === '' ? null : route('products.image', ['ref' => $ref]),
            'image_full_url' => $ref === '' ? null : route('products.image', ['ref' => $ref, 'size' => 'full']),
        ]]);
    }

    public function updateOrder(Request $request, Order $order): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'action' => ['required_without:fulfilment_status', 'in:cancel,confirm_cash_delivery'],
            'fulfilment_status' => ['required_without:action', Rule::in(Order::FULFILMENT_STATUSES)],
        ]);

        // Moving the parts along the line. Independent of the payment actions
        // below, so it is handled first and on its own.
        if (isset($data['fulfilment_status'])) {
            if (! $order->moveFulfilmentTo($data['fulfilment_status'])) {
                return response()->json(['message' => 'This order cannot be fulfilled in its current state.'], 422);
            }

            return response()->json(['data' => new OrderResource($order->fresh()->load(Order::ADMIN_RELATIONS))]);
        }

        if ($data['action'] === 'confirm_cash_delivery' && $order->payment_method === Order::METHOD_COD && $order->status === Order::STATUS_AWAITING_DELIVERY) {
            $order->forceFill(['status' => Order::STATUS_PAID, 'paid_at' => now()])->save();
            // Taking the cash *is* the handover, so the goods are delivered by
            // definition — otherwise every COD order would finish its life
            // sitting in "out for delivery".
            $order->moveFulfilmentTo(Order::FULFILMENT_DELIVERED);
            // Cash on delivery is confirmed by an admin rather than a payment
            // gateway callback, so nothing else pushes it to Dolibarr — do it here.
            $this->pusher->push($order);
        } elseif ($data['action'] === 'cancel' && $order->payment_method === Order::METHOD_COD && $order->status === Order::STATUS_AWAITING_DELIVERY) {
            $order->forceFill(['status' => Order::STATUS_CANCELLED])->save();
        } else {
            return response()->json(['message' => 'This order cannot make that transition.'], 422);
        }

        return response()->json(['data' => new OrderResource($order->fresh()->load(Order::ADMIN_RELATIONS))]);
    }

    /**
     * Retry pushing a paid order into Dolibarr as a sales order and invoice.
     * The push is idempotent (see DolibarrOrderPusher), so this is safe to
     * call whether the previous attempt partially succeeded or never ran.
     */
    public function retryErpSync(Request $request, Order $order): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $order->isPaid()) {
            return response()->json(['message' => 'Only a paid order can be synced to the ERP.'], 422);
        }

        if (! $this->pusher->enabled()) {
            return response()->json(['message' => 'ERP order push is turned off (DOLIBARR_PUSH_ORDERS=false).'], 422);
        }

        $this->pusher->push($order->fresh());

        // A return that failed to reach the ERP is retried with the order.
        foreach ($order->returns()->get() as $return) {
            $this->pusher->pushReturn($return);
        }

        return response()->json(['data' => new OrderResource($order->fresh()->load(Order::ADMIN_RELATIONS))]);
    }

    /**
     * Record goods sent back on a paid order and push them to the ERP as a
     * credit note (stock back into the warehouse, money refunded). The
     * dashboard asks the admin to confirm the items and quantities first.
     */
    public function createReturn(Request $request, Order $order): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $order->isPaid()) {
            return response()->json(['message' => 'Only a paid order can have items returned.'], 422);
        }

        // Refunding something the ERP never sold would leave its books worse
        // off than doing nothing, so the sale has to be there first.
        if ($this->pusher->enabled() && $order->dolibarr_invoice_id === null) {
            return response()->json(['message' => 'Sync this order to the ERP before recording a return.'], 422);
        }

        $return = DB::transaction(function () use ($order, $data, $request): OrderReturn|string {
            $items = $order->items()->lockForUpdate()->get()->keyBy('id');
            $lines = [];

            foreach ($data['items'] as $line) {
                $item = $items->get($line['order_item_id']);

                if ($item === null) {
                    return 'That item is not part of this order.';
                }
                if ($line['quantity'] > $item->returnableQuantity()) {
                    return sprintf('Only %d of %s can still be returned.', $item->returnableQuantity(), $item->ref);
                }

                $lines[] = [
                    'order_item_id' => $item->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->unit_price * $line['quantity'],
                ];
            }

            $return = $order->returns()->create([
                'reference' => sprintf('%s-R%d', $order->reference, $order->returns()->count() + 1),
                'total' => array_sum(array_column($lines, 'line_total')),
                'reason' => $data['reason'] ?? null,
                'created_by' => AccountController::user($request)?->id,
            ]);
            $return->items()->createMany($lines);

            return $return;
        });

        if (is_string($return)) {
            return response()->json(['message' => $return], 422);
        }

        // Everything back means the order itself has come back.
        if ($order->items()->get()->every(fn ($item) => $item->returnableQuantity() === 0)) {
            $order->moveFulfilmentTo(Order::FULFILMENT_RETURNED);
        }

        $this->pusher->pushReturn($return);

        return response()->json(['data' => new OrderResource($order->fresh()->load(Order::ADMIN_RELATIONS))], 201);
    }

    /**
     * One order's full detail (line items, address, notes) for the admin
     * order page — unlike CheckoutController::show, this trusts the admin
     * session rather than the order's access key.
     */
    public function showOrder(Request $request, Order $order): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['data' => new OrderResource($order->load(Order::ADMIN_RELATIONS))]);
    }

    /**
     * The same invoice PDF a customer can download, available to an admin
     * without the order's access key.
     */
    public function orderInvoice(Request $request, Order $order): Response|JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $order->hasInvoice()) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $lang = in_array($request->query('lang'), ['ar', 'ku'], true) ? $request->query('lang') : 'en';

        return response($this->invoices->render($order->load('items'), $lang), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$order->reference.'-INV.pdf"',
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['data' => [
            'total_orders' => Order::count(),
            'open_orders' => Order::whereIn('status', [Order::STATUS_PENDING, Order::STATUS_AWAITING_DELIVERY])->count(),
            'paid_revenue' => (int) Order::where('status', Order::STATUS_PAID)->sum('total'),
            'customer_accounts' => User::count(),
            'dealer_accounts' => User::where('role', 'dealer')->count(),
        ]]);
    }

    public function settings(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['data' => array_merge(
            collect(config('shop'))->only(['profit_percentage', 'usd_iqd_rate', 'rounding_increment', 'low_stock_threshold'])->all(),
            DB::table('shop_settings')->pluck('value', 'key')->all(),
            SiteSettings::all(),
        )]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'profit_percentage' => ['sometimes', 'numeric', 'between:0,1000'],
            'usd_iqd_rate' => ['sometimes', 'numeric', 'between:1,100000'],
            'rounding_increment' => ['sometimes', 'integer', 'between:1,100000'],
            'low_stock_threshold' => ['sometimes', 'numeric', 'between:0,100000'],
            'phone_number' => ['sometimes', 'string', 'min:5', 'max:40'],
            'whatsapp_number' => ['sometimes', 'string', 'min:5', 'max:20', 'regex:/^[0-9]+$/'],
            // Nullable: the storefront's ConvertEmptyStringsToNull middleware turns a
            // cleared field into null before it gets here. Null is stored as '', which
            // means "use the storefront's own translated default" (see SiteSettings).
            'free_delivery_text' => ['sometimes', 'nullable', 'string', 'max:120'],
            'trusted_since_text' => ['sometimes', 'nullable', 'string', 'max:120'],
            'store_address' => ['sometimes', 'nullable', 'string', 'max:200'],
            'store_hours' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        foreach ($data as $key => $value) {
            DB::table('shop_settings')->updateOrInsert(['key' => $key], ['value' => (string) $value, 'updated_at' => now(), 'created_at' => now()]);
        }

        return $this->settings($request);
    }

    private function authorized(Request $request): bool
    {
        return AccountController::user($request)?->role === 'admin';
    }

    /**
     * Why a product wouldn't appear in the storefront, or null when it would.
     *
     * Mirrors ErpProductController::catalogProduct()'s gate exactly — a
     * product is storefront-visible iff it isn't hidden and PricingEngine
     * considers it publishable — so this can't drift from what customers
     * actually see.
     */
    private function visibilityReason(float $cost, ?float $quantity, bool $isHidden): ?string
    {
        if ($isHidden) {
            return 'hidden';
        }

        if ($cost <= 0) {
            return 'no_cost';
        }

        if (! $this->pricing->isPublishable($cost, $quantity)) {
            return 'out_of_stock';
        }

        return null;
    }

    /**
     * The product detail page can afford two extra ERP round trips to catch
     * what visibilityReason() can't see from data already in hand: a product
     * that clears every local check yet still never reaches the storefront,
     * because DolibarrClient::products() filters on the raw `cost_price`/
     * `stock` columns before PricingEngine's fallback fields are considered.
     * Not run from products() — that would mean two extra ERP calls per row
     * across a batch of up to 300.
     */
    private function visibilityReasonDetailed(int $id, float $cost, ?float $quantity, bool $isHidden): ?string
    {
        $reason = $this->visibilityReason($cost, $quantity, $isHidden);

        if ($reason !== null) {
            return $reason;
        }

        if (! $this->dolibarr->matchesRawColumnFilter($id, 't.cost_price:>:0')) {
            return 'stale_cost_cache';
        }

        if (! $this->dolibarr->matchesRawColumnFilter($id, 't.stock:>:0')) {
            return 'stale_stock_cache';
        }

        return null;
    }
}
