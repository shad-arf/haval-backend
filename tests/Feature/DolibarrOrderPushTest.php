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
    }

    /** Fakes an empty ERP that knows one dealer, CU2609-00412. */
    private function fakeErp(): void
    {
        Http::fake(function (Request $request) {
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
