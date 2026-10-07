<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DolibarrRequestException;
use App\Http\Controllers\Controller;
use App\Models\ProductOverride;
use App\Services\DolibarrClient;
use App\Services\PricingEngine;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ErpProductController extends Controller
{
    public function __construct(
        private readonly DolibarrClient $dolibarr,
        private readonly PricingEngine $pricing,
    ) {}

    public function health(): JsonResponse
    {
        return $this->respond(function (): array {
            $this->dolibarr->products(1, 0, requireCost: false, requireStock: false);

            return ['connected' => true, 'service' => 'dolibarr'];
        });
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:0'],
            'brand' => ['sometimes', 'integer', 'min:1'],
            'q' => ['sometimes', 'string', 'max:80', 'regex:/^[\p{L}\p{N} _.\-]+$/u'],
        ]);

        $limit = (int) ($input['limit'] ?? 24);
        $page = (int) ($input['page'] ?? 0);
        $brandId = (int) ($input['brand'] ?? 0);
        $search = trim((string) ($input['q'] ?? ''));

        return $this->respond(function () use ($limit, $page, $brandId, $search): array {
            // Dolibarr loads every product and its warehouse stock one by one,
            // so a page costs it the better part of a second. Every visitor
            // asks for the same first pages; a minute of staleness is fine for
            // browsing because checkout re-reads the product from the ERP.
            $products = Cache::remember(
                sprintf('catalog.erp-page.%d.%d.%d.%s', $limit, $page, $brandId, md5($search)),
                now()->addMinute(),
                fn (): array => $this->dolibarr->products($limit, $page, $brandId, $search),
            );

            $overrides = ProductOverride::whereIn('erp_product_id', array_map(static fn (array $product): int => (int) ($product['id'] ?? 0), $products))
                ->get()
                ->keyBy('erp_product_id');
            $withPhotos = $this->dolibarr->productsWithPhotos(array_map(static fn (array $product): string => (string) ($product['ref'] ?? ''), $products));

            // The ERP query already excludes unpriceable and out-of-stock parts,
            // but a product can still fail the rules locally when the ERP
            // reports per-warehouse stock that disagrees with its cached total.
            $catalog = [];

            foreach ($products as $product) {
                $entry = $this->catalogProduct($product, $overrides->get((int) ($product['id'] ?? 0)), $withPhotos[(string) ($product['ref'] ?? '')] ?? true);

                if ($entry !== null) {
                    $catalog[] = $entry;
                }
            }

            // Parts with a photo lead the page; the sort is stable, so the
            // ERP's newest-first order holds within each group.
            usort($catalog, static fn (array $a, array $b): int => ($b['image_url'] !== null) <=> ($a['image_url'] !== null));

            return [
                'data' => $catalog,
                'page' => $page,
                'limit' => $limit,
                'has_more' => count($products) === $limit,
                'currency' => 'IQD',
            ];
        });
    }

    public function brands(): JsonResponse
    {
        return $this->respond(function (): array {
            $brands = $this->dolibarr->brands();

            return [
                'data' => array_map(static fn (array $brand): array => [
                    'id' => (int) $brand['id'],
                    'name' => (string) $brand['label'],
                ], $brands),
            ];
        });
    }

    public function show(int $id): JsonResponse
    {
        // Carts look parts up one by one, and several open tabs can ask for
        // the same ones at once; 30 seconds keeps that off the ERP. Checkout
        // re-reads every line itself, so a stale price or stock never sells.
        return $this->respondProduct(fn (): array => Cache::remember(
            'catalog.erp-product.'.$id,
            now()->addSeconds(30),
            fn (): array => $this->dolibarr->product($id),
        ));
    }

    public function byRef(string $ref): JsonResponse
    {
        return $this->respondProduct(fn (): array => $this->dolibarr->productByRef($ref));
    }

    /**
     * Shape one ERP product for the storefront, or null when it may not be sold.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>|null
     */
    /**
     * The signed-in dealer's profit, looked up once per request. Kept on the
     * request rather than the controller, which Laravel reuses across requests.
     */
    private function dealerProfit(): ?float
    {
        $request = request();

        if (! $request->attributes->has('dealer_profit')) {
            $request->attributes->set('dealer_profit', AccountController::user($request)?->dealerProfit($this->pricing));
        }

        return $request->attributes->get('dealer_profit');
    }

    private function catalogProduct(array $product, ?ProductOverride $override, bool $hasPhoto = true): ?array
    {
        $sourceCost = $this->pricing->sourceCost($product);
        $quantity = $this->pricing->stockQuantity($product);

        if (! $this->pricing->isPublishable($sourceCost, $quantity)) {
            return null;
        }

        if ($override?->is_hidden) {
            return null;
        }

        $costUsd = $this->pricing->costInUsd($sourceCost);
        $price = $this->pricing->sellingPrice($costUsd, $override?->profit_percentage);
        // Only a signed-in dealer is told their price; the response is
        // private (no shared cache), so it never reaches anyone else.
        $dealerProfit = $this->dealerProfit();
        $ref = (string) ($product['ref'] ?? '');
        $description = trim(strip_tags((string) ($product['description'] ?? '')));
        $shortDescription = trim(strip_tags((string) ($product['note_public'] ?? '')));

        return [
            'id' => (int) ($product['id'] ?? 0),
            'ref' => $ref,
            'name' => $override?->display_name ?: self::displayName($ref, (string) ($product['label'] ?? ''), $shortDescription, $description),
            'description' => $override?->description ?? $description,
            'short_description' => $shortDescription,
            // The regular price, struck through whenever a sale or dealer price is lower.
            'price' => $price,
            'sale_price' => $this->pricing->activeSalePrice($override?->sale_price, $price),
            'dealer_price' => $dealerProfit === null ? null : $this->pricing->payablePrice($costUsd, $override?->profit_percentage, $override?->sale_price, $dealerProfit),
            'currency' => 'IQD',
            'stock_quantity' => $quantity,
            'stock_status' => $this->pricing->stockStatus($quantity),
            'is_sellable' => (int) ($product['status'] ?? 0) === 1 && ($quantity === null || $quantity > 0),

            /**
             * Null when the listing already knows the part has no photo, so
             * the storefront shows its placeholder without a request that
             * can only 404. A single product is not checked; the storefront
             * falls back on its own if this 404s.
             */
            'image_url' => $ref === '' || ! $hasPhoto ? null : route('products.image', ['ref' => $ref]),
            'image_full_url' => $ref === '' || ! $hasPhoto ? null : route('products.image', ['ref' => $ref, 'size' => 'full']),
        ];
    }

    /**
     * Pick the best title for a part.
     *
     * Dolibarr's `label` is usually a duplicate of the part reference rather
     * than a real name, so fall back to the English name in `note_public` and
     * then the description before showing a bare code.
     */
    private static function displayName(string $ref, string $label, string $shortDescription, string $description): string
    {
        $label = trim($label);

        if ($label !== '' && $label !== $ref) {
            return $label;
        }

        foreach ([$shortDescription, $description, $ref, $label] as $candidate) {
            if (trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return $ref;
    }

    /**
     * Respond with a single product, treating a hidden product as not found.
     *
     * @param  callable(): array<string, mixed>  $fetch
     */
    private function respondProduct(callable $fetch): JsonResponse
    {
        return $this->respond(function () use ($fetch): array|JsonResponse {
            $erpProduct = $fetch();
            $product = $this->catalogProduct($erpProduct, ProductOverride::where('erp_product_id', (int) ($erpProduct['id'] ?? 0))->first());

            if ($product === null) {
                return response()->json(['message' => 'Product not found.'], 404);
            }

            return $product;
        });
    }

    /**
     * @param  callable(): (array<mixed>|JsonResponse)  $callback
     */
    private function respond(callable $callback): JsonResponse
    {
        try {
            $result = $callback();

            return $result instanceof JsonResponse ? $result : response()->json($result);
        } catch (DolibarrRequestException $exception) {
            $status = $exception->upstreamStatus === 404 ? 404 : 502;

            return response()->json([
                'message' => $status === 404 ? 'Product not found.' : 'The catalog request failed.',
            ], $status);
        } catch (ConnectionException $exception) {
            report($exception);

            return response()->json(['message' => 'The catalog is unavailable.'], 503);
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json(['message' => 'The catalog is unavailable.'], 503);
        }
    }
}
