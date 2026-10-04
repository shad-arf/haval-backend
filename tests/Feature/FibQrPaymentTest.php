<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FibQrPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('fib_qr.enabled', true);
        config()->set('fib_qr.base_url', 'https://qr.test');
        config()->set('fib_qr.client_id', 'test-client');
        config()->set('fib_qr.client_secret', 'test-secret');
        config()->set('fib_qr.callback_url', 'https://shop.test/api/payments/fib-qr/callback');
        config()->set('dolibarr.push_orders', false);
    }

    public function test_qr_method_requires_credentials(): void
    {
        $this->getJson('/api/payment-methods')->assertOk()->assertJsonFragment(['id' => Order::METHOD_FIB_QR]);
        config()->set('fib_qr.client_secret', '');
        $this->getJson('/api/payment-methods')->assertOk()->assertJsonMissing(['id' => Order::METHOD_FIB_QR]);
    }

    public function test_checkout_returns_a_qr_payment_for_the_saved_order(): void
    {
        config()->set('dolibarr.url', 'http://erp.test/api/index.php');
        config()->set('dolibarr.key', 'test-key');
        config()->set('shop.profit_percentage', 25);
        config()->set('shop.usd_iqd_rate', 1320);
        config()->set('shop.rounding_increment', 250);
        config()->set('shop.cost_currency', 'USD');
        Http::fake([
            'erp.test/*' => Http::response([
                'id' => '19902', 'ref' => 'DO-BS012', 'label' => 'Bushing',
                'status' => '1', 'cost_price' => 6.66, 'stock_reel' => 80,
            ]),
            'qr.test/auth/*' => Http::response(['access_token' => 'token']),
            'qr.test/protected/v1/payments' => Http::response([
                'paymentId' => 'payment-1', 'qrCode' => 'data:image/png;base64,aGVsbG8=',
                'readableCode' => 'ABCD-1234', 'validUntil' => '2030-10-01T00:00:00Z',
            ], 201),
        ]);

        $result = $this->postJson('/api/checkout', [
            'items' => [['product_id' => 19902, 'quantity' => 2]],
            'payment_method' => Order::METHOD_FIB_QR,
            'customer' => [
                'name' => 'Test Buyer', 'phone' => '07700000000',
                'city' => 'Erbil', 'address' => 'Industrial Area',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.payment_method', Order::METHOD_FIB_QR)
            ->assertJsonPath('data.qr_payment.readable_code', 'ABCD-1234')
            ->assertJsonPath('data.total', 22000);

        $this->assertNotEmpty($result->json('access_key'));
        $this->assertDatabaseHas('fib_qr_payment_attempts', ['payment_id' => 'payment-1', 'expected_amount' => 22000]);
    }

    public function test_unpaid_and_untrusted_callback_do_not_mark_order_paid(): void
    {
        $order = $this->order();
        $this->attempt($order);
        Http::fakeSequence()->push(['access_token' => 'token'], 200)->push([
            'paymentId' => 'payment-1', 'status' => 'UNPAID',
        ], 200);

        $this->postJson('/api/payments/fib-qr/callback', ['id' => 'payment-1', 'status' => 'PAID'])->assertOk();
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_verified_payment_with_matching_amount_marks_order_paid(): void
    {
        $order = $this->order();
        $this->attempt($order);
        Http::fakeSequence()->push(['access_token' => 'token'], 200)->push([
            'paymentId' => 'payment-1', 'status' => 'PAID',
            'amount' => ['amount' => '22000.00', 'currency' => 'IQD'],
        ], 200);

        $this->getJson("/api/orders/{$order->reference}/status?key={$order->access_key}")
            ->assertOk()->assertJsonPath('data.status', Order::STATUS_PAID);
        $this->assertNotNull($order->fresh()->paid_at);
    }

    public function test_amount_mismatch_is_held_for_review(): void
    {
        $order = $this->order();
        $this->attempt($order);
        Http::fakeSequence()->push(['access_token' => 'token'], 200)->push([
            'paymentId' => 'payment-1', 'status' => 'PAID',
            'amount' => ['amount' => '100.00', 'currency' => 'IQD'],
        ], 200);

        $this->getJson("/api/orders/{$order->reference}/status?key={$order->access_key}")
            ->assertOk()->assertJsonPath('data.status', Order::STATUS_ON_HOLD);
    }

    public function test_declined_payment_can_be_retried_with_a_new_qr(): void
    {
        $order = $this->order();
        $this->attempt($order);
        Http::fakeSequence()->push(['access_token' => 'token'], 200)->push([
            'paymentId' => 'payment-1', 'status' => 'DECLINED', 'decliningReason' => 'PAYMENT_EXPIRATION',
        ], 200)->push(['access_token' => 'token'], 200)->push([
            'paymentId' => 'payment-1', 'status' => 'DECLINED', 'decliningReason' => 'PAYMENT_EXPIRATION',
        ], 200)->push(['access_token' => 'token'], 200)->push([
            'paymentId' => 'payment-2', 'qrCode' => 'data:image/png;base64,aGVsbG8=',
            'validUntil' => '2030-10-01T00:00:00Z',
        ], 202);
        $this->getJson("/api/orders/{$order->reference}/status?key={$order->access_key}")
            ->assertJsonPath('data.status', Order::STATUS_FAILED);

        $this->postJson("/api/orders/{$order->reference}/pay?key={$order->access_key}")
            ->assertOk()->assertJsonPath('data.qr_payment.qr_code', 'data:image/png;base64,aGVsbG8=');
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertDatabaseCount('fib_qr_payment_attempts', 2);
    }

    /** FIB answering by path; $status is what the open QR reports. */
    private function fakeFib(string $status): void
    {
        Http::fake(function (Request $request) use ($status) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/cancel') => Http::response(null, 204),
                str_ends_with($path, '/status') => Http::response(['paymentId' => 'payment-1', 'status' => $status, 'amount' => ['amount' => '22000.00', 'currency' => 'IQD']]),
                default => Http::response(['access_token' => 'token']),
            };
        });
    }

    public function test_abandoning_an_unpaid_qr_order_cancels_the_code_at_fib(): void
    {
        $this->fakeFib('UNPAID');
        $order = $this->order();
        $this->attempt($order);

        $this->postJson("/api/orders/{$order->reference}/abandon?key={$order->access_key}")
            ->assertOk()->assertJsonPath('data.status', Order::STATUS_CANCELLED);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/payments/payment-1/cancel'));
        $this->assertSame('DECLINED', $order->fibQrAttempts()->first()->last_status);
    }

    public function test_a_qr_paid_at_the_last_second_is_not_abandoned(): void
    {
        $this->fakeFib('PAID');
        $order = $this->order();
        $this->attempt($order);

        $this->postJson("/api/orders/{$order->reference}/abandon?key={$order->access_key}")
            ->assertOk()->assertJsonPath('data.status', Order::STATUS_PAID);

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/cancel'));
    }

    public function test_abandon_needs_the_order_key(): void
    {
        $order = $this->order();

        $this->postJson("/api/orders/{$order->reference}/abandon?key=wrong")->assertNotFound();
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_switching_a_qr_order_to_cash_cancels_the_qr(): void
    {
        $this->fakeFib('UNPAID');
        $order = $this->order();
        $this->attempt($order);

        $this->postJson("/api/orders/{$order->reference}/pay?key={$order->access_key}", ['payment_method' => Order::METHOD_COD])
            ->assertOk()->assertJsonPath('data.status', Order::STATUS_AWAITING_DELIVERY);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/payments/payment-1/cancel'));
    }

    private function order(): Order
    {
        return Order::create([
            'reference' => 'WEB-000001', 'access_key' => Order::newAccessKey(),
            'status' => Order::STATUS_PENDING, 'payment_method' => Order::METHOD_FIB_QR,
            'customer_name' => 'Test Buyer', 'customer_phone' => '07700000000',
            'city' => 'Erbil', 'address_line' => 'Industrial Area',
            'subtotal' => 22000, 'total' => 22000, 'currency' => 'IQD',
        ]);
    }

    private function attempt(Order $order): void
    {
        $order->fibQrAttempts()->create([
            'payment_id' => 'payment-1', 'expected_amount' => 22000,
            'qr_code' => 'data:image/png;base64,aGVsbG8=',
            'valid_until' => now()->addMinutes(10),
        ]);
    }
}
