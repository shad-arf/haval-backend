<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminShopTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $token = 'admin-test-token';
        User::factory()->create(['role' => 'admin', 'api_token_hash' => hash('sha256', $token)]);

        return $token;
    }

    public function test_only_admin_can_set_local_product_override(): void
    {
        $this->patchJson('/api/admin/products/42', ['is_hidden' => true])->assertForbidden();
        $product = ['id' => 42, 'ref' => 'PART-42', 'label' => 'Test part', 'status' => 1, 'cost_price' => 10, 'stock_reel' => 8];
        Http::fake(['erp.test/*' => Http::response($product)]);
        config()->set('dolibarr.url', 'http://erp.test/api/index.php');
        config()->set('dolibarr.key', 'test-key');

        $this->withToken($this->adminToken())->patchJson('/api/admin/products/42', [
            'profit_percentage' => 50, 'display_name' => 'Local title',
        ])->assertOk();

        $this->getJson('/api/products/42')->assertOk()->assertJsonPath('name', 'Local title')->assertJsonPath('price', 20000);
        $this->withToken('admin-test-token')->patchJson('/api/admin/products/42', ['is_hidden' => true])->assertOk();
        $this->getJson('/api/products/42')->assertNotFound();
    }

    public function test_admin_can_create_dealer_but_cannot_mark_card_order_paid(): void
    {
        $this->withToken($this->adminToken())->postJson('/api/admin/users', [
            'name' => 'Trade buyer', 'username' => 'trade_buyer', 'phone' => '07702222222', 'password' => 'secret123',
            'role' => 'dealer', 'dealer_profit_percentage' => 30,
            'discount_expires_at' => now()->addDay()->toISOString(), 'show_discount_countdown' => true,
        ])->assertCreated()->assertJsonPath('data.role', 'dealer')->assertJsonPath('data.show_discount_countdown', true);

        $dealer = User::where('username', 'trade_buyer')->firstOrFail();
        $this->withToken('admin-test-token')->patchJson('/api/admin/users/'.$dealer->id, [
            'discount_expires_at' => null, 'show_discount_countdown' => false,
        ])->assertOk()->assertJsonPath('data.discount_expires_at', null)->assertJsonPath('data.show_discount_countdown', false);

        $order = Order::create([
            'reference' => 'WEB-999999', 'access_key' => Order::newAccessKey(),
            'status' => Order::STATUS_PENDING, 'payment_method' => Order::METHOD_FIB_CARD,
            'customer_name' => 'Test', 'customer_phone' => '07700000000', 'city' => 'Erbil',
            'address_line' => 'Street', 'subtotal' => 1000, 'total' => 1000,
        ]);
        $this->withToken('admin-test-token')->patchJson('/api/admin/orders/'.$order->reference, [
            'action' => 'confirm_cash_delivery',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => Order::STATUS_PENDING]);
    }

    public function test_admin_pricing_settings_change_storefront_price(): void
    {
        config()->set('dolibarr.url', 'http://erp.test/api/index.php');
        config()->set('dolibarr.key', 'test-key');
        Http::fake(['erp.test/*' => Http::response([
            'id' => 42, 'ref' => 'PART-42', 'label' => 'Test part',
            'status' => 1, 'cost_price' => 10, 'stock_reel' => 8,
        ])]);

        $this->withToken($this->adminToken())->patchJson('/api/admin/settings', [
            'profit_percentage' => 50, 'usd_iqd_rate' => 1000, 'rounding_increment' => 100,
        ])->assertOk();
        $this->getJson('/api/products/42')->assertOk()->assertJsonPath('price', 15000);
    }

    public function test_admin_products_endpoint_filters_sorts_and_paginates_on_the_backend(): void
    {
        config()->set('dolibarr.url', 'http://erp.test/api/index.php');
        config()->set('dolibarr.key', 'test-key');
        config()->set('shop.cost_currency', 'USD');
        config()->set('shop.profit_percentage', 0);
        config()->set('shop.usd_iqd_rate', 100);
        config()->set('shop.rounding_increment', 1);
        config()->set('shop.low_stock_threshold', 5);

        $rows = [
            ['id' => 1, 'ref' => 'A-1', 'label' => 'Alpha part', 'status' => 1, 'cost_price' => 10, 'stock_reel' => 0],
            ['id' => 2, 'ref' => 'B-2', 'label' => 'Bravo part', 'status' => 1, 'cost_price' => 20, 'stock_reel' => 100],
            ['id' => 3, 'ref' => 'C-3', 'label' => 'Charlie part', 'status' => 1, 'cost_price' => 5, 'stock_reel' => 50],
            ['id' => 4, 'ref' => 'D-4', 'label' => 'Delta part', 'status' => 1, 'cost_price' => 30, 'stock_reel' => 2],
        ];
        Http::fake(['erp.test/*' => Http::response($rows)]);

        $token = $this->adminToken();

        $this->withToken($token)->patchJson('/api/admin/products/2', ['is_hidden' => true])->assertOk();

        $this->withToken($token)->getJson('/api/admin/products?visibility=hidden')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 2)
            ->assertJsonPath('total', 1);

        $this->withToken($token)->getJson('/api/admin/products?visibility=visible')
            ->assertOk()
            ->assertJsonPath('total', 3);

        $this->withToken($token)->getJson('/api/admin/products?stock=out_of_stock')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 1);

        $response = $this->withToken($token)->getJson('/api/admin/products?sort=name_asc')->assertOk();
        $this->assertSame(
            ['Alpha part', 'Bravo part', 'Charlie part', 'Delta part'],
            collect($response->json('data'))->pluck('name')->all(),
        );

        $response = $this->withToken($token)->getJson('/api/admin/products?sort=price_desc')->assertOk();
        $this->assertSame([4, 2, 1, 3], collect($response->json('data'))->pluck('id')->all());

        $response = $this->withToken($token)
            ->getJson('/api/admin/products?visibility=visible&stock=in_stock')
            ->assertOk();
        $this->assertSame(1, $response->json('total'));
        $this->assertFalse($response->json('has_more'));
    }

    public function test_admin_products_endpoint_reports_why_a_product_is_not_storefront_visible(): void
    {
        config()->set('dolibarr.url', 'http://erp.test/api/index.php');
        config()->set('dolibarr.key', 'test-key');

        $rows = [
            // No cost price at all: unpriceable, so it can never be publishable.
            ['id' => 1, 'ref' => 'A-1', 'label' => 'Alpha part', 'status' => 1, 'cost_price' => 0, 'stock_reel' => 10],
            // Has a cost but no stock, and the storefront hides out-of-stock parts by default.
            ['id' => 2, 'ref' => 'B-2', 'label' => 'Bravo part', 'status' => 1, 'cost_price' => 10, 'stock_reel' => 0],
            // Priced and in stock: would be publishable until hidden below.
            ['id' => 3, 'ref' => 'C-3', 'label' => 'Charlie part', 'status' => 1, 'cost_price' => 10, 'stock_reel' => 5],
            ['id' => 4, 'ref' => 'D-4', 'label' => 'Delta part', 'status' => 1, 'cost_price' => 10, 'stock_reel' => 5],
        ];
        Http::fake(['erp.test/*' => Http::response($rows)]);

        $token = $this->adminToken();
        $this->withToken($token)->patchJson('/api/admin/products/3', ['is_hidden' => true])->assertOk();

        $response = $this->withToken($token)->getJson('/api/admin/products')->assertOk();
        $byId = collect($response->json('data'))->keyBy('id');

        $this->assertFalse($byId[1]['storefront_visible']);
        $this->assertSame('no_cost', $byId[1]['visibility_reason']);

        $this->assertFalse($byId[2]['storefront_visible']);
        $this->assertSame('out_of_stock', $byId[2]['visibility_reason']);

        $this->assertFalse($byId[3]['storefront_visible']);
        $this->assertSame('hidden', $byId[3]['visibility_reason']);

        $this->assertTrue($byId[4]['storefront_visible']);
        $this->assertNull($byId[4]['visibility_reason']);
    }

    public function test_admin_can_view_a_single_product_detail_and_only_admin_can(): void
    {
        config()->set('dolibarr.url', 'http://erp.test/api/index.php');
        config()->set('dolibarr.key', 'test-key');
        $product = [
            'id' => 42, 'ref' => 'PART-42', 'label' => 'Test part', 'description' => '<p>Fits many models</p>',
            'status' => 1, 'cost_price' => 0, 'stock_reel' => 8,
        ];
        Http::fake([
            'erp.test/api/index.php/products/42*' => Http::response($product),
            'erp.test/api/index.php/products/999*' => Http::response(['error' => ['message' => 'not found']], 404),
        ]);

        $this->getJson('/api/admin/products/42')->assertForbidden();

        $token = $this->adminToken();
        $this->withToken($token)->getJson('/api/admin/products/42')
            ->assertOk()
            ->assertJsonPath('data.ref', 'PART-42')
            ->assertJsonPath('data.erp_description', 'Fits many models')
            ->assertJsonPath('data.has_cost', false)
            ->assertJsonPath('data.storefront_visible', false)
            ->assertJsonPath('data.visibility_reason', 'no_cost');

        $this->withToken($token)->getJson('/api/admin/products/999')->assertNotFound();
    }

    public function test_admin_products_endpoint_requires_admin(): void
    {
        $this->getJson('/api/admin/products')->assertForbidden();
    }

    public function test_dashboard_summary_is_admin_only_and_counts_all_orders(): void
    {
        $this->getJson('/api/admin/summary')->assertForbidden();
        $token = $this->adminToken();
        Order::create([
            'reference' => 'WEB-888888', 'access_key' => Order::newAccessKey(),
            'status' => Order::STATUS_AWAITING_DELIVERY, 'payment_method' => Order::METHOD_COD,
            'customer_name' => 'Test', 'customer_phone' => '07700000000', 'city' => 'Erbil',
            'address_line' => 'Street', 'subtotal' => 1000, 'total' => 1000,
        ]);

        $this->withToken($token)->getJson('/api/admin/summary')->assertOk()
            ->assertJsonPath('data.total_orders', 1)
            ->assertJsonPath('data.open_orders', 1)
            ->assertJsonPath('data.paid_revenue', 0)
            ->assertJsonPath('data.customer_accounts', 1);
    }

    public function test_admin_can_customize_site_content_and_the_public_settings_endpoint_reflects_it(): void
    {
        $this->getJson('/api/settings')->assertOk()
            ->assertJsonPath('data.phone_number', '+964 782 450 0404')
            ->assertJsonPath('data.trusted_since_text', '');

        $this->withToken($this->adminToken())->patchJson('/api/admin/settings', [
            'phone_number' => '+964 750 000 0000',
            'whatsapp_number' => '9647500000000',
            'free_delivery_text' => 'Same-day delivery in Erbil',
            'trusted_since_text' => 'Trusted since 1986',
            'store_address' => 'Runaki Street, Erbil',
            'store_hours' => 'Every day · 09:00–18:00',
        ])->assertOk()->assertJsonPath('data.phone_number', '+964 750 000 0000');

        $this->getJson('/api/settings')->assertOk()
            ->assertJsonPath('data.phone_number', '+964 750 000 0000')
            ->assertJsonPath('data.whatsapp_number', '9647500000000')
            ->assertJsonPath('data.free_delivery_text', 'Same-day delivery in Erbil')
            ->assertJsonPath('data.trusted_since_text', 'Trusted since 1986')
            ->assertJsonPath('data.store_address', 'Runaki Street, Erbil')
            ->assertJsonPath('data.store_hours', 'Every day · 09:00–18:00');
    }

    public function test_site_settings_reject_non_numeric_whatsapp_numbers(): void
    {
        $this->withToken($this->adminToken())->patchJson('/api/admin/settings', [
            'whatsapp_number' => 'not-a-number',
        ])->assertUnprocessable();
    }

    private function orderWithItems(array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'reference' => 'WEB-100001',
            'access_key' => Order::newAccessKey(),
            'status' => Order::STATUS_AWAITING_DELIVERY,
            'payment_method' => Order::METHOD_COD,
            'customer_name' => 'Test Buyer',
            'customer_phone' => '07700000000',
            'city' => 'Erbil',
            'address_line' => 'Industrial Area',
            'notes' => 'Leave with the guard.',
            'subtotal' => 22000,
            'total' => 22000,
        ], $overrides));

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

    public function test_admin_can_view_full_order_detail_without_an_access_key(): void
    {
        $order = $this->orderWithItems();

        $this->getJson("/api/admin/orders/{$order->reference}")->assertForbidden();

        $this->withToken($this->adminToken())->getJson("/api/admin/orders/{$order->reference}")
            ->assertOk()
            ->assertJsonPath('data.customer.address', 'Industrial Area')
            ->assertJsonPath('data.customer.notes', 'Leave with the guard.')
            ->assertJsonPath('data.items.0.ref', 'DO-BS012')
            ->assertJsonPath('data.items.0.quantity', 2);
    }

    public function test_admin_can_download_the_invoice_for_a_paid_order(): void
    {
        $order = $this->orderWithItems([
            'reference' => 'WEB-100002', 'status' => Order::STATUS_PAID, 'payment_method' => Order::METHOD_FIB_CARD, 'paid_at' => now(),
        ]);

        $response = $this->withToken($this->adminToken())->get("/api/admin/orders/{$order->reference}/invoice");
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_admin_can_move_an_order_along_the_fulfilment_chain(): void
    {
        $order = $this->orderWithItems(['reference' => 'WEB-100010']);
        $token = $this->adminToken();

        $this->assertSame(Order::FULFILMENT_NOT_STARTED, $order->fulfilment_status);

        $this->withToken($token)->patchJson("/api/admin/orders/{$order->reference}", [
            'fulfilment_status' => Order::FULFILMENT_PREPARING,
        ])->assertOk()->assertJsonPath('data.fulfilment.status', Order::FULFILMENT_PREPARING);

        // Stages are not forward-only: a picker has to be able to undo a
        // mis-click, so stepping back is allowed.
        $this->withToken($token)->patchJson("/api/admin/orders/{$order->reference}", [
            'fulfilment_status' => Order::FULFILMENT_AWAITING_STOCK,
        ])->assertOk()->assertJsonPath('data.fulfilment.status', Order::FULFILMENT_AWAITING_STOCK);

        $this->assertNotNull($order->fresh()->fulfilment_updated_at);
    }

    public function test_fulfilment_is_rejected_for_an_order_that_is_not_being_handled(): void
    {
        $token = $this->adminToken();

        // Unpaid card order: nothing should be picked for it yet.
        $unpaid = $this->orderWithItems([
            'reference' => 'WEB-100011', 'status' => Order::STATUS_PENDING, 'payment_method' => Order::METHOD_FIB_CARD,
        ]);
        $this->withToken($token)->patchJson("/api/admin/orders/{$unpaid->reference}", [
            'fulfilment_status' => Order::FULFILMENT_PREPARING,
        ])->assertStatus(422);
        $this->assertSame(Order::FULFILMENT_NOT_STARTED, $unpaid->fresh()->fulfilment_status);

        // Cancelled order: it must not keep moving down the line.
        $cancelled = $this->orderWithItems(['reference' => 'WEB-100012', 'status' => Order::STATUS_CANCELLED]);
        $this->withToken($token)->patchJson("/api/admin/orders/{$cancelled->reference}", [
            'fulfilment_status' => Order::FULFILMENT_OUT_FOR_DELIVERY,
        ])->assertStatus(422);

        $this->withToken($token)->patchJson("/api/admin/orders/{$cancelled->reference}", [
            'fulfilment_status' => 'teleported',
        ])->assertStatus(422);
    }

    public function test_confirming_cash_marks_the_order_delivered(): void
    {
        $order = $this->orderWithItems(['reference' => 'WEB-100013']);

        $this->withToken($this->adminToken())->patchJson("/api/admin/orders/{$order->reference}", [
            'action' => 'confirm_cash_delivery',
        ])->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_PAID)
            // Taking the cash is the handover, so the goods are delivered.
            ->assertJsonPath('data.fulfilment.status', Order::FULFILMENT_DELIVERED);
    }

    /**
     * The Arabic and Kurdish invoices take a different path through mPDF —
     * right-to-left directionality, script-driven font substitution — so a
     * template change can break them while the English one still renders.
     */
    public function test_the_invoice_renders_in_every_language(): void
    {
        $order = $this->orderWithItems([
            'reference' => 'WEB-100004', 'status' => Order::STATUS_PAID, 'payment_method' => Order::METHOD_COD, 'paid_at' => now(),
        ]);

        $token = $this->adminToken();

        foreach (['en', 'ar', 'ku'] as $lang) {
            $response = $this->withToken($token)->get("/api/admin/orders/{$order->reference}/invoice?lang={$lang}");

            $response->assertOk();
            $this->assertStringStartsWith('%PDF', $response->getContent(), "{$lang} invoice is not a PDF");
        }
    }

    public function test_admin_invoice_download_404s_for_an_unpaid_order(): void
    {
        $order = $this->orderWithItems(['reference' => 'WEB-100003']);

        $this->withToken($this->adminToken())->getJson("/api/admin/orders/{$order->reference}/invoice")->assertNotFound();
    }
}
