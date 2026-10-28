<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\FibCardsClient;
use App\Services\FibPaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The payment decision. Everything here guards against marking an order paid
 * when it was not, or failing one that was.
 */
class FibPaymentReconcilerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('fib.enabled', true);
        config()->set('fib.hmac_secret', 'test-secret');
        config()->set('fib.uat_base_url', 'https://fib.test/ACS24');
        config()->set('dolibarr.push_orders', false);
    }

    private function order(string $status = Order::STATUS_PENDING, int $total = 22000): Order
    {
        $order = Order::create([
            'reference' => 'WEB-000001',
            'access_key' => Order::newAccessKey(),
            'status' => $status,
            'payment_method' => Order::METHOD_FIB_CARD,
            'customer_name' => 'Test Buyer',
            'customer_phone' => '07700000000',
            'city' => 'Erbil',
            'address_line' => 'Industrial Area',
            'subtotal' => $total,
            'total' => $total,
        ]);

        $order->fibAttempts()->create([
            'fib_order_id' => '1790250482833150',
            'payment_id' => 'pay-1',
            'expected_amount' => $total,
        ]);

        return $order;
    }

    /**
     * Token first, then status — both are faked in sequence.
     *
     * @param  array<string, mixed>  $status
     */
    private function fakeFib(array $status, int $statusHttp = 200): void
    {
        Http::fakeSequence()
            ->push(['accessToken' => 'token'], 200)
            ->push($status, $statusHttp);
    }

    private function reconcile(Order $order): Order
    {
        return app(FibPaymentReconciler::class)->reconcile($order, 'test');
    }

    // ------------------------------------------------------------- success --

    public function test_a_success_with_the_approval_code_marks_the_order_paid(): void
    {
        $this->fakeFib(['paymentData' => ['status' => 'SUCCESS', 'returnCode' => '000', 'amount' => '22000']]);

        $order = $this->reconcile($this->order());

        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->paid_at);
    }

    public function test_a_paid_order_gains_an_invoice(): void
    {
        $this->fakeFib(['paymentData' => ['status' => 'SUCCESS', 'returnCode' => '000', 'amount' => '22000']]);

        $this->assertTrue($this->reconcile($this->order())->hasInvoice());
    }

    // ------------------------------------------------------- not-yet states --

    /**
     * The critical UAT finding: merely opening the FIB card page creates the
     * transaction with status ERROR. Treating that as a decline would fail
     * every order the moment the customer arrived at the card page.
     */
    public function test_error_leaves_the_order_pending_rather_than_failing_it(): void
    {
        $this->fakeFib(['paymentData' => ['status' => 'ERROR']]);

        $this->assertSame(Order::STATUS_PENDING, $this->reconcile($this->order())->status);
    }

    public function test_error_909_after_a_card_attempt_holds_the_order_for_review(): void
    {
        $this->fakeFib(['paymentData' => [
            'status' => 'ERROR',
            'returnCode' => '909',
            'hiddenCardNumber' => '410000******5000',
        ]]);

        $this->assertSame(Order::STATUS_ON_HOLD, $this->reconcile($this->order())->status);
    }

    public function test_error_909_without_a_card_attempt_stays_pending(): void
    {
        $this->fakeFib(['paymentData' => ['status' => 'ERROR', 'returnCode' => '909']]);

        $this->assertSame(Order::STATUS_PENDING, $this->reconcile($this->order())->status);
    }

    /**
     * FIB answers 404 "No transaction found" until the card page is completed.
     */
    public function test_no_transaction_found_leaves_the_order_pending(): void
    {
        $this->fakeFib(['errorCode' => '404', 'description' => 'No transaction found'], 404);

        $this->assertSame(Order::STATUS_PENDING, $this->reconcile($this->order())->status);
    }

    public function test_an_unreachable_gateway_leaves_the_order_pending(): void
    {
        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->assertSame(Order::STATUS_PENDING, $this->reconcile($this->order())->status);
    }

    // --------------------------------------------------------- disagreement --

    /**
     * A successful-looking status with a non-approval return code is a
     * contradiction, and it is not resolved in the store's favour.
     */
    public function test_a_success_without_the_approval_code_is_held_not_paid(): void
    {
        $this->fakeFib(['paymentData' => ['status' => 'SUCCESS', 'returnCode' => '116', 'codeDescription' => 'Insufficient funds']]);

        $order = $this->reconcile($this->order());

        $this->assertSame(Order::STATUS_ON_HOLD, $order->status);
        $this->assertNull($order->paid_at);
    }

    /**
     * If FIB settled a different amount than the order is worth, a human looks
     * at it. Never an automatic approval.
     */
    public function test_a_success_for_the_wrong_amount_is_held_not_paid(): void
    {
        $this->fakeFib(['paymentData' => ['status' => 'SUCCESS', 'returnCode' => '000', 'amount' => '250']]);

        $order = $this->reconcile($this->order(total: 22000));

        $this->assertSame(Order::STATUS_ON_HOLD, $order->status);
        $this->assertNull($order->paid_at);
    }

    public function test_an_amount_fib_does_not_report_cannot_contradict_the_order(): void
    {
        $this->fakeFib(['paymentData' => ['status' => 'SUCCESS', 'returnCode' => '000']]);

        $this->assertSame(Order::STATUS_PAID, $this->reconcile($this->order())->status);
    }

    // -------------------------------------------------------------- failure --

    public function test_a_decline_fails_the_order(): void
    {
        $this->fakeFib(['paymentData' => ['status' => 'DECLINED', 'returnCode' => '116']]);

        $this->assertSame(Order::STATUS_FAILED, $this->reconcile($this->order())->status);
    }

    public function test_a_cancellation_cancels_the_order(): void
    {
        $this->fakeFib(['paymentData' => ['status' => 'CANCELLED']]);

        $this->assertSame(Order::STATUS_CANCELLED, $this->reconcile($this->order())->status);
    }

    // ------------------------------------------------------------ settled --

    public function test_a_settled_order_is_never_re_checked(): void
    {
        Http::fake();

        $this->reconcile($this->order(Order::STATUS_PAID));

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ attempts --

    /**
     * A customer who opens the card page, goes back, retries and then completes
     * the first attempt has still paid. The money counts whichever attempt took it.
     */
    public function test_a_completed_earlier_attempt_still_pays_the_order(): void
    {
        $order = $this->order();
        $order->fibAttempts()->create([
            'fib_order_id' => '1790250482833151',
            'payment_id' => 'pay-2',
            'expected_amount' => $order->total,
        ]);

        Http::fakeSequence()
            // Newest attempt: nothing there yet.
            ->push(['accessToken' => 'token'], 200)
            ->push(['errorCode' => '404'], 404)
            // Older attempt: actually paid.
            ->push(['accessToken' => 'token'], 200)
            ->push(['paymentData' => ['status' => 'SUCCESS', 'returnCode' => '000', 'amount' => '22000']], 200);

        $this->assertSame(Order::STATUS_PAID, $this->reconcile($order)->status);
    }

    /**
     * The mirror image: an abandoned earlier attempt must not fail an order
     * whose current attempt is still open.
     */
    public function test_an_abandoned_earlier_attempt_does_not_fail_a_live_order(): void
    {
        $order = $this->order();
        $order->fibAttempts()->create([
            'fib_order_id' => '1790250482833152',
            'payment_id' => 'pay-2',
            'expected_amount' => $order->total,
        ]);

        Http::fakeSequence()
            ->push(['accessToken' => 'token'], 200)
            ->push(['errorCode' => '404'], 404)
            ->push(['accessToken' => 'token'], 200)
            ->push(['paymentData' => ['status' => 'DECLINED']], 200);

        $this->assertSame(Order::STATUS_PENDING, $this->reconcile($order)->status);
    }

    // ------------------------------------------------------------ callback --

    /**
     * Anyone can post to the callback, so its body is only ever used to decide
     * which order to look at — never what happened to it.
     */
    public function test_the_callback_body_cannot_declare_an_order_paid(): void
    {
        $order = $this->order();

        Http::fakeSequence()
            ->push(['accessToken' => 'token'], 200)
            ->push(['errorCode' => '404'], 404);

        $body = [
            'orderId' => '1790250482833150',
            'status' => 'SUCCESS',
            'returnCode' => '000',
        ];
        $signature = FibCardsClient::sign(FibCardsClient::canonicalJson($body), 'test-secret');
        $this->postJson('/api/payments/fib/callback', $body, ['X-HMAC' => $signature])->assertOk();

        $this->assertSame(Order::STATUS_PENDING, $order->refresh()->status);
    }

    public function test_a_callback_for_an_unknown_order_is_acknowledged_without_action(): void
    {
        Http::fake();

        $body = ['orderId' => 'nope'];
        $signature = FibCardsClient::sign(FibCardsClient::canonicalJson($body), 'test-secret');
        $this->postJson('/api/payments/fib/callback', $body, ['X-HMAC' => $signature])
            ->assertOk()
            ->assertJsonPath('received', true);

        Http::assertNothingSent();
    }

    public function test_unsigned_or_forged_callbacks_are_rejected_without_checking_payment(): void
    {
        $order = $this->order();
        Http::fake();
        $body = ['orderId' => '1790250482833150', 'status' => 'SUCCESS'];

        $this->postJson('/api/payments/fib/callback', $body)->assertUnauthorized();
        $this->postJson('/api/payments/fib/callback', $body, ['X-HMAC' => str_repeat('0', 128)])->assertUnauthorized();
        $signature = FibCardsClient::sign(FibCardsClient::canonicalJson($body), 'test-secret');
        $body['status'] = 'FAILED';
        $this->postJson('/api/payments/fib/callback', $body, ['X-HMAC' => $signature])->assertUnauthorized();

        Http::assertNothingSent();
        $this->assertSame(Order::STATUS_PENDING, $order->refresh()->status);
    }

    public function test_a_valid_signed_callback_confirms_payment_using_fib_status(): void
    {
        $order = $this->order();
        $this->fakeFib(['paymentData' => ['status' => 'SUCCESS', 'returnCode' => '000', 'amount' => '22000']]);
        $body = ['status' => 'PENDING', 'orderId' => '1790250482833150'];
        $signature = FibCardsClient::sign(FibCardsClient::canonicalJson($body), 'test-secret');

        $this->postJson('/api/payments/fib/callback', $body, ['X-HMAC' => $signature])->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->refresh()->status);
    }

    public function test_callback_verification_preserves_nested_objects_and_list_order(): void
    {
        $client = app(FibCardsClient::class);
        $body = '{"orderId":"123","details":{"z":{},"a":[{"z":2,"a":1},3]}}';
        $canonical = '{"details":{"a":[{"a":1,"z":2},3],"z":{}},"orderId":"123"}';
        $signature = hash_hmac('sha512', $canonical, 'test-secret');

        $this->assertTrue($client->verifyCallback($body, $signature));
        $this->assertFalse($client->verifyCallback('invalid json', $signature));
        $this->assertFalse($client->verifyCallback('[]', $signature));
        config()->set('fib.hmac_secret', '');
        $this->assertFalse($client->verifyCallback($body, $signature));
    }

    public function test_scheduled_checks_cancel_a_payment_fib_reports_as_expired(): void
    {
        foreach (['terminal_number', 'institution', 'merchant_id', 'acquirer'] as $key) {
            config()->set('fib.'.$key, 'test-value');
        }
        $order = $this->order();
        $this->fakeFib(['paymentData' => ['status' => 'EXPIRED']]);

        $this->artisan('fib:reconcile-payments')->assertSuccessful();

        $this->assertSame(Order::STATUS_CANCELLED, $order->refresh()->status);
        $this->assertNull($order->paid_at);
    }

    public function test_scheduled_checks_keep_old_unresolved_payments_unpaid_without_guessing_expiry(): void
    {
        foreach (['terminal_number', 'institution', 'merchant_id', 'acquirer'] as $key) {
            config()->set('fib.'.$key, 'test-value');
        }
        $order = $this->order();
        $order->update(['created_at' => now()->subDays(2)]);
        $this->fakeFib(['paymentData' => ['status' => 'ERROR']]);

        $this->artisan('fib:reconcile-payments')->assertSuccessful();

        $this->assertSame(Order::STATUS_PENDING, $order->refresh()->status);
        $this->assertNull($order->paid_at);
    }

    public function test_scheduled_checks_skip_paid_orders(): void
    {
        foreach (['terminal_number', 'institution', 'merchant_id', 'acquirer'] as $key) {
            config()->set('fib.'.$key, 'test-value');
        }
        $this->order(Order::STATUS_PAID);
        Http::fake();

        $this->artisan('fib:reconcile-payments')->assertSuccessful();

        Http::assertNothingSent();
    }
}
