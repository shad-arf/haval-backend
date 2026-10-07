<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Exceptions\DolibarrRequestException;
use App\Models\Order;
use App\Models\ProductOverride;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a browser cart into an order.
 *
 * The cart arriving from the browser carries product IDs and quantities and
 * nothing else. Prices are re-derived from the ERP here and stock is re-checked,
 * so a tampered cart cannot set its own price and a part that sold out while
 * the customer was browsing cannot be ordered.
 */
class CheckoutService
{
    public function __construct(
        private readonly DolibarrClient $dolibarr,
        private readonly PricingEngine $pricing,
    ) {}

    /**
     * @param  array<int, array{product_id: int, quantity: int}>  $lines
     * @param  array<string, string|null>  $customer
     *
     * @throws CheckoutException
     */
    public function placeOrder(array $lines, array $customer, string $paymentMethod, ?User $account = null): Order
    {
        // A dealer is priced up from cost at the dealer profit, not discounted
        // down from the customer price — see PricingEngine::dealerPrice.
        $priced = $this->priceLines($lines, $account?->dealerProfit($this->pricing));

        if ($priced === []) {
            throw new CheckoutException('Your cart is empty.');
        }

        $subtotal = array_sum(array_column($priced, 'line_total'));

        return DB::transaction(function () use ($priced, $subtotal, $customer, $paymentMethod): Order {
            $order = Order::create([
                'reference' => $this->nextReference(),
                'access_key' => Order::newAccessKey(),
                // Cash on delivery is placed, not paid; a card order waits for FIB.
                'status' => $paymentMethod === Order::METHOD_COD
                    ? Order::STATUS_AWAITING_DELIVERY
                    : Order::STATUS_PENDING,
                'payment_method' => $paymentMethod,
                'customer_name' => $customer['name'],
                'customer_phone' => $customer['phone'],
                'city' => $customer['city'],
                'address_line' => $customer['address'],
                'notes' => $customer['notes'] ?? null,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'currency' => 'IQD',
            ]);

            $order->items()->createMany($priced);

            return $order->load('items');
        });
    }

    /**
     * Re-price every cart line against the ERP.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $lines
     * @return array<int, array<string, mixed>>
     *
     * @throws CheckoutException
     */
    private function priceLines(array $lines, ?float $dealerProfit = null): array
    {
        $priced = [];
        $unavailable = [];
        $merged = $this->mergeDuplicates($lines);

        try {
            $products = $this->dolibarr->productsById(array_keys($merged));
        } catch (RuntimeException) {
            throw new CheckoutException('We could not confirm your cart with the warehouse. Please try again.');
        }

        $overrides = ProductOverride::whereIn('erp_product_id', array_keys($merged))->get()->keyBy('erp_product_id');

        foreach ($merged as $productId => $quantity) {
            $product = $products[$productId] ?? new RuntimeException('Missing from the ERP response.');

            if ($product instanceof DolibarrRequestException && $product->upstreamStatus === 404) {
                $unavailable[] = (string) $productId;

                continue;
            }

            if ($product instanceof RuntimeException) {
                throw new CheckoutException('We could not confirm your cart with the warehouse. Please try again.');
            }

            $cost = $this->pricing->sourceCost($product);
            $stock = $this->pricing->stockQuantity($product);
            $ref = (string) ($product['ref'] ?? $productId);
            $override = $overrides->get($productId);

            // The same rules the catalog publishes by. A part that fails them
            // now cannot be bought, whatever the cart still believes.
            if ($override?->is_hidden || ! $this->pricing->isPublishable($cost, $stock) || (int) ($product['status'] ?? 0) !== 1) {
                $unavailable[] = $ref;

                continue;
            }

            if ($stock !== null && $stock < $quantity) {
                throw new CheckoutException(sprintf(
                    'Only %d of %s remain in stock. Please adjust the quantity.',
                    (int) $stock,
                    $ref,
                ));
            }

            $unitPrice = $this->pricing->payablePrice($this->pricing->costInUsd($cost), $override?->profit_percentage, $override?->sale_price, $dealerProfit);

            $priced[] = [
                'erp_product_id' => (int) ($product['id'] ?? $productId),
                'ref' => $ref,
                'name' => $override?->display_name ?: $this->displayName($product, $ref),
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_total' => $unitPrice * $quantity,
            ];
        }

        if ($unavailable !== []) {
            throw new CheckoutException(sprintf(
                'These parts are no longer available: %s. Please remove them and try again.',
                implode(', ', $unavailable),
            ));
        }

        return $priced;
    }

    /**
     * Collapse repeated product IDs so a cart cannot smuggle in two lines for
     * the same part and pass a stock check each line would fail together.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $lines
     * @return array<int, int>
     */
    private function mergeDuplicates(array $lines): array
    {
        $merged = [];

        foreach ($lines as $line) {
            $id = (int) $line['product_id'];
            $merged[$id] = ($merged[$id] ?? 0) + (int) $line['quantity'];
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function displayName(array $product, string $ref): string
    {
        $label = trim((string) ($product['label'] ?? ''));

        if ($label !== '' && $label !== $ref) {
            return $label;
        }

        foreach ([$product['note_public'] ?? '', $product['description'] ?? ''] as $candidate) {
            $candidate = trim(strip_tags((string) $candidate));

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $ref;
    }

    /**
     * Sequential, human-quotable, and the idempotency key when the order is
     * pushed to Dolibarr.
     */
    private function nextReference(): string
    {
        $lastId = (int) (Order::max('id') ?? 0);

        return sprintf('WEB-%06d', $lastId + 1);
    }
}
