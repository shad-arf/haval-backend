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

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dolibarr.url', 'http://erp.test/api/index.php');
        config()->set('dolibarr.key', 'test-key');
        config()->set('dolibarr.push_orders', true);
        config()->set('dolibarr.customer_id', self::ONLINE_CUSTOMER);
        config()->set('dolibarr.warehouse_id', 6);
        config()->set('dolibarr.bank_account_id', 1);
    }

    /** Fakes an empty ERP that knows one dealer, CU2609-00412. */
    private function fakeErp(float $remainToPay = 14.34): void
    {
        Http::fake(function (Request $request) use ($remainToPay) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $query = urldecode((string) parse_url($request->url(), PHP_URL_QUERY));

            return match (true) {
                str_ends_with($path, '/thirdparties') => str_contains($query, "'CU2609-00412'")
                    ? Http::response([['id' => self::DEALER_CUSTOMER]])
                    : Http::response(['error' => 'Not found'], 404),
                $request->method() === 'GET' && (str_ends_with($path, '/orders') || str_ends_with($path, '/invoices')) => Http::response(['error' => 'Not found'], 404),
                $request->method() === 'POST' && str_ends_with($path, '/orders') => Http::response('501'),
                $request->method() === 'POST' && str_ends_with($path, '/invoices') => Http::response('601'),
                str_ends_with($path, '/lines') => Http::response('1'),
                str_ends_with($path, '/validate') => Http::response(['ok' => true]),
                str_ends_with($path, '/payments') => Http::response('77'),
                str_ends_with($path, '/invoices/601') => Http::response(['lines' => [], 'paye' => $remainToPay > 0 ? 0 : 1, 'remaintopay' => $remainToPay]),
                default => Http::response(['lines' => []]),
            };
        });
    }

    private function paidOrder(?User $user = null): Order
    {
        $order = Order::create([
            'reference' => 'WEB-777777', 'access_key' => Order::newAccessKey(),
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
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/invoices/601/validate') && $request['idwarehouse'] === 6);
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
        $this->fakeErp();
        $order = $this->paidOrder();
        $order->update(['payment_method' => Order::METHOD_COD]);

        $this->assertTrue(app(DolibarrOrderPusher::class)->push($order));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/payments') && $request['paymentid'] === 4);
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
}
