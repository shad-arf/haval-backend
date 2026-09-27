<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dolibarr.url', 'http://erp.test/api/index.php');
        config()->set('dolibarr.key', 'test-key');
        config()->set('dolibarr.push_orders', false);
        config()->set('shop', [
            'profit_percentage' => 25,
            'usd_iqd_rate' => 1320,
            'rounding_increment' => 250,
            'cost_currency' => 'USD',
            'hide_out_of_stock' => true,
            'low_stock_threshold' => 5,
        ]);
        config()->set('fib.enabled', true);
        config()->set('fib.hmac_secret', 'test-secret');
        config()->set('fib.uat_base_url', 'https://fib.test/ACS24');
        config()->set('fib.callback_url', '');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function erpProduct(array $overrides = []): array
    {
        return array_merge([
            'id' => '19902',
            'ref' => 'DO-BS012',
            'label' => 'Bushing',
            'status' => '1',
            'description' => '',
            'note_public' => '',
            'cost_price' => 6.66,
            'stock_reel' => 80,
        ], $overrides);
    }

    private function fakeErp(array $product): void
    {
        Http::fake(['erp.test/*' => Http::response($product)]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function checkoutPayload(array $overrides = []): array
    {
        return array_merge([
            'items' => [['product_id' => 19902, 'quantity' => 2]],
            'payment_method' => 'cod',
            'customer' => [
                'name' => 'Test Buyer',
                'phone' => '07700000000',
                'city' => 'Erbil',
                'address' => 'Industrial Area',
            ],
        ], $overrides);
    }

    // ---------------------------------------------------------- repricing --

    /**
     * The whole point of re-pricing: the browser sends IDs and quantities, and
     * whatever it might believe about price is irrelevant.
     */
    public function test_the_price_is_derived_from_the_erp_not_from_the_cart(): void
    {
        $this->fakeErp($this->erpProduct(['cost_price' => 6.66]));

        $response = $this->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['product_id' => 19902, 'quantity' => 2, 'unit_price' => 1, 'line_total' => 2]],
        ]));

        // 6.66 * 1.25 * 1320 = 10,989 -> rounds up to 11,000.
        $response->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', 11000)
            ->assertJsonPath('data.total', 22000);
    }

    public function test_dealer_discount_is_applied_to_server_priced_lines(): void
    {
        $this->fakeErp($this->erpProduct());
        $dealer = User::factory()->create(['role' => 'dealer', 'discount_percent' => 10]);
        $token = 'dealer-test-token';
        $dealer->forceFill(['api_token_hash' => hash('sha256', $token)])->save();

        // 11,000 * 0.9 = 9,900 -> rounds up to the nearest 250, same as every other shelf price.
        $this->withToken($token)->postJson('/api/checkout', $this->checkoutPayload())
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', 10000)
            ->assertJsonPath('data.total', 20000);

        $this->assertDatabaseHas('orders', ['user_id' => $dealer->id, 'total' => 20000]);
    }

    public function test_duplicate_cart_lines_for_one_part_are_merged(): void
    {
        $this->fakeErp($this->erpProduct());

        $this->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [
                ['product_id' => 19902, 'quantity' => 1],
                ['product_id' => 19902, 'quantity' => 2],
            ],
        ]))
            ->assertCreated()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.total', 33000);
    }

    public function test_ordering_more_than_the_erp_has_in_stock_is_refused(): void
    {
        $this->fakeErp($this->erpProduct(['stock_reel' => 3]));

        $this->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['product_id' => 19902, 'quantity' => 4]],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only 3 of DO-BS012 remain in stock. Please adjust the quantity.');

        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * Two lines for the same part must be summed before the stock check, or a
     * split cart could buy more than exists.
     */
    public function test_split_lines_cannot_beat_the_stock_check(): void
    {
        $this->fakeErp($this->erpProduct(['stock_reel' => 3]));

        $this->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [
                ['product_id' => 19902, 'quantity' => 2],
                ['product_id' => 19902, 'quantity' => 2],
            ],
        ]))->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_part_that_sold_out_while_browsing_cannot_be_ordered(): void
    {
        $this->fakeErp($this->erpProduct(['stock_reel' => 0]));

        $this->postJson('/api/checkout', $this->checkoutPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'These parts are no longer available: DO-BS012. Please remove them and try again.');
    }

    public function test_a_part_with_no_cost_cannot_be_ordered(): void
    {
        $this->fakeErp($this->erpProduct(['cost_price' => 0, 'pmp' => 0]));

        $this->postJson('/api/checkout', $this->checkoutPayload())->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    // ------------------------------------------------------------- orders --

    public function test_a_cash_on_delivery_order_is_placed_not_paid(): void
    {
        $this->fakeErp($this->erpProduct());

        $this->postJson('/api/checkout', $this->checkoutPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', Order::STATUS_AWAITING_DELIVERY)
            ->assertJsonPath('data.payment_method', 'cod')
            ->assertJsonPath('data.invoice', null);
    }

    public function test_an_unpaid_order_has_no_invoice(): void
    {
        $this->fakeErp($this->erpProduct());

        $this->postJson('/api/checkout', $this->checkoutPayload())
            ->assertCreated()
            ->assertJsonPath('data.invoice', null);
    }

    public function test_a_paid_order_exposes_an_invoice(): void
    {
        $order = $this->paidOrder();

        $this->getJson("/api/orders/{$order->reference}?key={$order->access_key}")
            ->assertOk()
            ->assertJsonPath('data.invoice.number', $order->reference.'-INV');
    }

    public function test_the_response_never_contains_an_erp_cost(): void
    {
        $this->fakeErp($this->erpProduct());

        $body = $this->postJson('/api/checkout', $this->checkoutPayload())->assertCreated()->json();

        $this->assertStringNotContainsString('cost', strtolower(json_encode($body['data'])));
    }

    // ------------------------------------------------------- access control --

    public function test_an_order_cannot_be_read_without_its_access_key(): void
    {
        $order = $this->paidOrder();

        $this->getJson("/api/orders/{$order->reference}")->assertNotFound();
        $this->getJson("/api/orders/{$order->reference}?key=wrong")->assertNotFound();
    }

    /**
     * A wrong key and a missing order answer identically, so the endpoint
     * cannot be used to discover which references exist.
     */
    public function test_a_wrong_key_is_indistinguishable_from_a_missing_order(): void
    {
        $order = $this->paidOrder();

        $wrongKey = $this->getJson("/api/orders/{$order->reference}?key=wrong");
        $missing = $this->getJson('/api/orders/WEB-999999?key=wrong');

        $this->assertSame($wrongKey->status(), $missing->status());
        $this->assertSame($wrongKey->json(), $missing->json());
    }

    // ---------------------------------------------------------- validation --

    public function test_an_unknown_payment_method_is_rejected(): void
    {
        $this->postJson('/api/checkout', $this->checkoutPayload(['payment_method' => 'bitcoin']))
            ->assertStatus(422);
    }

    public function test_the_card_method_is_not_offered_when_the_gateway_is_unconfigured(): void
    {
        config()->set('fib.hmac_secret', '');

        $this->getJson('/api/payment-methods')
            ->assertOk()
            ->assertJsonMissing(['id' => Order::METHOD_FIB_CARD]);

        $this->postJson('/api/checkout', $this->checkoutPayload(['payment_method' => 'fib_card']))
            ->assertStatus(422);
    }

    /**
     * Customers recognise their card, not the acquirer, so the method is named
     * for the cards it takes and never mentions the bank.
     */
    public function test_the_card_method_is_presented_by_card_brand_not_by_bank(): void
    {
        $method = $this->getJson('/api/payment-methods')->assertOk()->json('data.0');

        $this->assertSame(Order::METHOD_FIB_CARD, $method['id']);
        $this->assertStringNotContainsStringIgnoringCase('fib', $method['label'].' '.$method['description']);
        $this->assertSame(['visa', 'mastercard', 'qicard'], $method['cards']);
    }

    public function test_checkout_requires_customer_contact_details(): void
    {
        $this->postJson('/api/checkout', ['items' => [['product_id' => 1, 'quantity' => 1]], 'payment_method' => 'cod'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer.name', 'customer.phone', 'customer.city', 'customer.address']);
    }

    public function test_an_empty_cart_is_rejected(): void
    {
        $this->postJson('/api/checkout', $this->checkoutPayload(['items' => []]))->assertStatus(422);
    }

    private function paidOrder(): Order
    {
        $order = Order::create([
            'reference' => 'WEB-000001',
            'access_key' => Order::newAccessKey(),
            'status' => Order::STATUS_PAID,
            'payment_method' => Order::METHOD_FIB_CARD,
            'customer_name' => 'Test Buyer',
            'customer_phone' => '07700000000',
            'city' => 'Erbil',
            'address_line' => 'Industrial Area',
            'subtotal' => 22000,
            'total' => 22000,
            'paid_at' => now(),
        ]);

        $order->items()->create([
            'erp_product_id' => 19902,
            'ref' => 'DO-BS012',
            'name' => 'Bushing',
            'unit_price' => 11000,
            'quantity' => 2,
            'line_total' => 22000,
        ]);

        return $order;
    }
}
