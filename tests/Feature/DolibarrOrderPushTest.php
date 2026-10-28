<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\DolibarrOrderPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DolibarrOrderPushTest extends TestCase
{
    use RefreshDatabase;

    private const ONLINE_CUSTOMER = 9001;

    private const DEALER_CUSTOMER = 1153;

    /** Flipped by a test to make the faked ERP answer every request with a 500. */
    private bool $erpDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dolibarr.url', 'http://erp.test/api/index.php');
        config()->set('dolibarr.key', 'test-key');
        config()->set('dolibarr.push_orders', true);
        config()->set('dolibarr.push_sales_orders', true);
        config()->set('dolibarr.customer_id', self::ONLINE_CUSTOMER);
        config()->set('dolibarr.warehouse_id', 6);
        config()->set('dolibarr.bank_account_id', 1);
        config()->set('dolibarr.online_bank_account_id', 0);
    }

    /** Fakes an empty ERP that knows one dealer, CU2609-00412. */
    private function fakeErp(float $remainToPay = 14.34, ?string $paymentError = null): void
    {
        Http::fake(function (Request $request) use ($remainToPay, $paymentError) {
            if ($this->erpDown) {
                return Http::response(['error' => 'down'], 500);
            }

            $path = parse_url($request->url(), PHP_URL_PATH);
            $query = urldecode((string) parse_url($request->url(), PHP_URL_QUERY));

            return match (true) {
                str_ends_with($path, '/thirdparties') => str_contains($query, "'CU2609-00412'")
                    ? Http::response([['id' => self::DEALER_CUSTOMER]])
                    : Http::response(['error' => 'Not found'], 404),
                $request->method() === 'GET' && (str_ends_with($path, '/orders') || str_ends_with($path, '/invoices')) => Http::response(['error' => 'Not found'], 404),
                $request->method() === 'POST' && str_ends_with($path, '/orders') => Http::response('501'),
                $request->method() === 'POST' && str_ends_with($path, '/invoices') => Http::response(($request['type'] ?? 0) === 2 ? '701' : '601'),
                str_ends_with($path, '/lines') => Http::response('1'),
                str_ends_with($path, '/validate') => Http::response(['ok' => true]),
                str_ends_with($path, '/payments') => $paymentError === null
                    ? Http::response('77')
                    : Http::response(['error' => ['code' => 400, 'message' => $paymentError]], 400),
                str_ends_with($path, '/paymentsdistributed') => Http::response('78'),
                str_ends_with($path, '/invoices/601') => Http::response(['lines' => [], 'socid' => self::DEALER_CUSTOMER, 'paye' => $remainToPay > 0 ? 0 : 1, 'remaintopay' => $remainToPay]),
                str_ends_with($path, '/invoices/701') => Http::response(['lines' => [], 'status' => 1, 'paye' => 0, 'remaintopay' => -7.17]),
                default => Http::response(['lines' => []]),
            };
        });
    }

    private function paidOrder(?User $user = null, string $reference = 'WEB-777777'): Order
    {
        $order = Order::create([
            'reference' => $reference, 'access_key' => Order::newAccessKey(),
            'status' => Order::STATUS_PAID, 'payment_method' => Order::METHOD_FIB_CARD,
            'customer_name' => 'Test', 'customer_phone' => '07700000000', 'city' => 'Erbil',
            'address_line' => 'Street', 'subtotal' => 1000, 'total' => 1000, 'paid_at' => now(),
        ]);
        $order->forceFill(['user_id' => $user?->id])->save();

        return $order;
    }

    private function bookedCustomer(): ?int
    {
        $created = Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/orders'));

        return $created->first()[0]['socid'] ?? null;
    }

    public function test_guest_order_is_booked_against_the_online_customer_and_moves_stock(): void
    {
        $this->fakeErp();
        $order = $this->paidOrder();

        $ok = app(DolibarrOrderPusher::class)->push($order);
        $this->assertTrue($ok);

        $this->assertSame(self::ONLINE_CUSTOMER, $this->bookedCustomer());
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'dolibarr_order_id' => 501, 'dolibarr_invoice_id' => 601, 'dolibarr_error' => null]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/invoices/601/validate')
            && $request['idwarehouse'] === 6 && $request['force_number'] === 'WEB-777777');
    }

    public function test_lines_are_written_in_the_erp_currency_not_iqd(): void
    {
        config()->set('shop.usd_iqd_rate', 1320);
        $this->fakeErp();
        $order = $this->paidOrder();
        $order->items()->create(['erp_product_id' => 42, 'ref' => 'PART-42', 'name' => 'Part', 'unit_price' => 21500, 'quantity' => 2, 'line_total' => 43000]);

        $this->assertTrue(app(DolibarrOrderPusher::class)->push($order));

        // 21,500 IQD at 1,320 IQD/USD — never a $21,500 line.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/invoices/601/lines')
            && $request['subprice'] === 16.29 && $request['qty'] === 2 && $request['fk_product'] === 42
            && str_contains($request['desc'], '21500 IQD @ 1320 IQD/USD'));
    }

    public function test_paid_order_closes_its_invoice_with_a_payment(): void
    {
        $this->fakeErp();
        $order = $this->paidOrder();

        $this->assertTrue(app(DolibarrOrderPusher::class)->push($order));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/invoices/601/payments')
            && $request['paymentid'] === 6 && $request['accountid'] === 1
            && $request['closepaidinvoices'] === 'yes' && $request['num_payment'] === 'WEB-777777');
        $this->assertNotNull($order->fresh()->dolibarr_paid_at);
        $this->assertTrue($order->fresh()->isErpSynced());
    }

    public function test_cash_on_delivery_is_recorded_as_cash(): void
    {
        config()->set('dolibarr.online_bank_account_id', 3);
        $this->fakeErp();
        $order = $this->paidOrder();
        $order->update(['payment_method' => Order::METHOD_COD]);

        $this->assertTrue(app(DolibarrOrderPusher::class)->push($order));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/payments') && $request['paymentid'] === 4 && $request['accountid'] === 1);
    }

    public function test_card_payment_goes_to_the_online_bank_account(): void
    {
        config()->set('dolibarr.online_bank_account_id', 3);
        $this->fakeErp();

        $this->assertTrue(app(DolibarrOrderPusher::class)->push($this->paidOrder()));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/payments') && $request['paymentid'] === 6 && $request['accountid'] === 3);
    }

    public function test_online_payments_are_recorded_when_only_the_online_bank_account_is_configured(): void
    {
        config()->set('dolibarr.bank_account_id', 0);
        config()->set('dolibarr.online_bank_account_id', 3);
        $this->fakeErp();

        foreach ([Order::METHOD_FIB_CARD, Order::METHOD_FIB_QR] as $method) {
            $order = $this->paidOrder(reference: 'WEB-'.$method);
            $order->update(['payment_method' => $method, 'dolibarr_invoice_id' => 601, 'dolibarr_order_id' => 501, 'dolibarr_synced_at' => now()]);

            $this->assertFalse($order->fresh()->isErpSynced());
            $this->assertTrue(app(DolibarrOrderPusher::class)->push($order));
            $this->assertNotNull($order->fresh()->dolibarr_paid_at);
            $this->assertTrue($order->fresh()->isErpSynced());

            Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/invoices/601/payments')
                && $request['accountid'] === 3
                && $request['paymentid'] === (int) config('dolibarr.payment_modes.'.$method));
        }

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/invoices'));
    }

    public function test_rejected_payment_preserves_the_erp_error_and_existing_invoice(): void
    {
        $this->fakeErp(paymentError: 'Add payment to bank error : ErrorBankAccountNotFound');
        $order = $this->paidOrder();
        $order->update(['dolibarr_invoice_id' => 601, 'dolibarr_order_id' => 501]);

        $this->assertFalse(app(DolibarrOrderPusher::class)->push($order));
        $this->assertSame(601, $order->fresh()->dolibarr_invoice_id);
        $this->assertNull($order->fresh()->dolibarr_paid_at);
        $this->assertFalse($order->fresh()->isErpSynced());
        $this->assertSame('Dolibarr: Add payment to bank error : ErrorBankAccountNotFound (HTTP 400)', $order->fresh()->dolibarr_error);
    }

    public function test_an_already_paid_invoice_is_not_paid_twice(): void
    {
        $this->fakeErp(remainToPay: 0);
        $order = $this->paidOrder();
        // A previous push created the invoice and its payment landed, but the response was lost.
        $order->update(['dolibarr_invoice_id' => 601, 'dolibarr_synced_at' => now()]);

        $this->assertTrue(app(DolibarrOrderPusher::class)->push($order));

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/payments'));
        $this->assertNotNull($order->fresh()->dolibarr_paid_at);
    }

    public function test_an_invoice_pushed_before_payments_were_enabled_gets_its_payment(): void
    {
        $this->fakeErp();
        $order = $this->paidOrder();
        $order->update(['dolibarr_invoice_id' => 601, 'dolibarr_synced_at' => now()]);

        $this->assertTrue(app(DolibarrOrderPusher::class)->push($order));

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/invoices'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/invoices/601/payments'));
    }

    public function test_no_payment_is_recorded_without_a_bank_account(): void
    {
        config()->set('dolibarr.bank_account_id', 0);
        $this->fakeErp();

        $this->assertTrue(app(DolibarrOrderPusher::class)->push($this->paidOrder()));

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/payments'));
    }

    public function test_dealer_order_is_booked_against_their_matching_dolibarr_customer(): void
    {
        $this->fakeErp();
        $dealer = User::factory()->create(['role' => 'dealer']);
        $dealer->forceFill(['customer_code' => 'CU2609-00412'])->save();

        $this->assertTrue(app(DolibarrOrderPusher::class)->push($this->paidOrder($dealer)));

        $this->assertSame(self::DEALER_CUSTOMER, $this->bookedCustomer());
    }

    public function test_dealer_with_an_unknown_code_falls_back_to_the_online_customer(): void
    {
        $this->fakeErp();
        $dealer = User::factory()->create(['role' => 'dealer']);
        $dealer->forceFill(['customer_code' => 'CU0000-99999'])->save();

        $this->assertTrue(app(DolibarrOrderPusher::class)->push($this->paidOrder($dealer)));

        $this->assertSame(self::ONLINE_CUSTOMER, $this->bookedCustomer());
    }

    public function test_invoice_only_push_skips_the_sales_order(): void
    {
        config()->set('dolibarr.push_sales_orders', false);
        $this->fakeErp();
        $order = $this->paidOrder();

        $this->assertTrue(app(DolibarrOrderPusher::class)->push($order));
        $this->assertTrue(app(DolibarrOrderPusher::class)->push($order->fresh()));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'dolibarr_order_id' => null, 'dolibarr_invoice_id' => 601]);
        Http::assertNotSent(fn (Request $request) => str_contains(parse_url($request->url(), PHP_URL_PATH), '/orders'));
        // find, create, read lines, validate, read balance, pay — and nothing on the second push.
        Http::assertSentCount(6);
    }

    public function test_admin_cannot_save_a_customer_code_dolibarr_does_not_know(): void
    {
        $this->fakeErp();
        $token = 'admin-test-token';
        User::factory()->create(['role' => 'admin', 'api_token_hash' => hash('sha256', $token)]);
        $dealer = User::factory()->create(['role' => 'dealer']);

        $this->withToken($token)->patchJson("/api/admin/users/{$dealer->id}", ['customer_code' => 'CU0000-99999'])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_code');
        $this->withToken($token)->patchJson("/api/admin/users/{$dealer->id}", ['customer_code' => ' CU2609-00412 '])
            ->assertOk()->assertJsonPath('data.customer_code', 'CU2609-00412');
    }

    public function test_sync_endpoint_explains_when_push_is_turned_off(): void
    {
        config()->set('dolibarr.push_orders', false);
        $token = 'admin-test-token';
        User::factory()->create(['role' => 'admin', 'api_token_hash' => hash('sha256', $token)]);
        $order = $this->paidOrder();

        $this->withToken($token)->postJson("/api/admin/orders/{$order->reference}/sync-erp")
            ->assertUnprocessable()->assertJsonPath('message', 'ERP order push is turned off (DOLIBARR_PUSH_ORDERS=false).');
    }

    private function adminWithToken(): string
    {
        $token = 'admin-test-token';
        User::factory()->create(['role' => 'admin', 'api_token_hash' => hash('sha256', $token)]);

        return $token;
    }

    /** A paid order already invoiced in the ERP, with two lines. */
    private function invoicedOrder(): Order
    {
        $order = $this->paidOrder();
        $order->items()->create(['erp_product_id' => 42, 'ref' => 'PART-42', 'name' => 'Plug', 'unit_price' => 10750, 'quantity' => 2, 'line_total' => 21500]);
        $order->items()->create(['erp_product_id' => 43, 'ref' => 'PART-43', 'name' => 'Filter', 'unit_price' => 5000, 'quantity' => 1, 'line_total' => 5000]);
        $order->update(['dolibarr_invoice_id' => 601, 'dolibarr_paid_at' => now(), 'dolibarr_synced_at' => now()]);

        return $order->fresh('items');
    }

    public function test_partial_return_creates_a_refunded_credit_note_that_restocks(): void
    {
        config()->set('shop.usd_iqd_rate', 1500);
        $this->fakeErp();
        $order = $this->invoicedOrder();
        $plug = $order->items->firstWhere('ref', 'PART-42');

        $this->withToken($this->adminWithToken())->postJson("/api/admin/orders/{$order->reference}/returns", [
            'items' => [['order_item_id' => $plug->id, 'quantity' => 1]], 'reason' => 'Wrong part',
        ])->assertCreated()
            ->assertJsonPath('data.returns.0.reference', 'WEB-777777-R1')
            ->assertJsonPath('data.returns.0.total', 10750)
            ->assertJsonPath('data.returns.0.erp.credit_note_id', 701)
            ->assertJsonPath('data.items.0.returned_quantity', 1);

        // A credit note against the invoice, on the invoice's own customer.
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/invoices')
            && $request['type'] === 2 && $request['fk_facture_source'] === 601 && $request['socid'] === self::DEALER_CUSTOMER
            && $request['ref_client'] === 'WEB-777777-R1');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/invoices/701/lines') && $request['qty'] === 1 && $request['subprice'] === 7.17 && $request['fk_product'] === 42);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/invoices/701/validate') && $request['idwarehouse'] === 6 && $request['force_number'] === 'WEB-777777-R1');
        // Refunds go through paymentsdistributed with a plain amount — see DolibarrClient::addCreditNoteRefund.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/invoices/paymentsdistributed')
            && $request['arrayofamounts'] === ['701' => ['amount' => 'remain', 'multicurrency_amount' => '']]
            && $request['accountid'] === 1 && $request['num_payment'] === 'WEB-777777-R1' && $request['closepaidinvoices'] === 'yes');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/invoices/701/payments'));
        $this->assertNotSame(Order::FULFILMENT_RETURNED, $order->fresh()->fulfilment_status);
    }

    public function test_returning_everything_marks_the_order_returned(): void
    {
        $this->fakeErp();
        $order = $this->invoicedOrder();

        $this->withToken($this->adminWithToken())->postJson("/api/admin/orders/{$order->reference}/returns", [
            'items' => $order->items->map(fn ($item) => ['order_item_id' => $item->id, 'quantity' => $item->quantity])->all(),
        ])->assertCreated()->assertJsonPath('data.returns.0.total', 26500);

        $this->assertSame(Order::FULFILMENT_RETURNED, $order->fresh()->fulfilment_status);
    }

    public function test_cannot_return_more_than_was_bought_across_returns(): void
    {
        $this->fakeErp();
        $order = $this->invoicedOrder();
        $plug = $order->items->firstWhere('ref', 'PART-42');
        $token = $this->adminWithToken();

        $this->withToken($token)->postJson("/api/admin/orders/{$order->reference}/returns", ['items' => [['order_item_id' => $plug->id, 'quantity' => 1]]])->assertCreated();
        $this->withToken($token)->postJson("/api/admin/orders/{$order->reference}/returns", ['items' => [['order_item_id' => $plug->id, 'quantity' => 2]]])
            ->assertUnprocessable()->assertJsonPath('message', 'Only 1 of PART-42 can still be returned.');
        $this->withToken($token)->postJson("/api/admin/orders/{$order->reference}/returns", ['items' => [['order_item_id' => $plug->id, 'quantity' => 1]]])
            ->assertCreated()->assertJsonPath('data.returns.1.reference', 'WEB-777777-R2');
    }

    public function test_an_order_not_yet_in_the_erp_cannot_be_returned(): void
    {
        $this->fakeErp();
        $order = $this->paidOrder();
        $item = $order->items()->create(['erp_product_id' => 42, 'ref' => 'PART-42', 'name' => 'Plug', 'unit_price' => 10750, 'quantity' => 1, 'line_total' => 10750]);

        $this->withToken($this->adminWithToken())->postJson("/api/admin/orders/{$order->reference}/returns", ['items' => [['order_item_id' => $item->id, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonPath('message', 'Sync this order to the ERP before recording a return.');
        $this->assertDatabaseCount('order_returns', 0);
    }

    public function test_a_failed_return_push_is_kept_and_retried_by_sync(): void
    {
        $order = $this->invoicedOrder();
        $plug = $order->items->firstWhere('ref', 'PART-42');
        $token = $this->adminWithToken();
        $this->fakeErp();
        $this->erpDown = true;

        $this->withToken($token)->postJson("/api/admin/orders/{$order->reference}/returns", ['items' => [['order_item_id' => $plug->id, 'quantity' => 1]]])
            ->assertCreated()->assertJsonPath('data.returns.0.erp.error', 'Dolibarr request failed. (HTTP 500)');

        $this->erpDown = false;
        $this->withToken($token)->postJson("/api/admin/orders/{$order->reference}/sync-erp")
            ->assertOk()->assertJsonPath('data.returns.0.erp.credit_note_id', 701)->assertJsonPath('data.returns.0.erp.error', null);
    }
}
