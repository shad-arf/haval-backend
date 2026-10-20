<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_logs_are_admin_only_and_filter_empty_results(): void
    {
        $this->getJson('/api/admin/searches')->assertForbidden();
        $this->getJson('/api/admin/search-insights')->assertForbidden();
        $customer = User::factory()->create(['api_token_hash' => hash('sha256', 'customer')]);
        $this->withToken('customer')->getJson('/api/admin/searches')->assertForbidden();
        User::factory()->create(['role' => 'admin', 'api_token_hash' => hash('sha256', 'admin')]);
        foreach ([[0, false], [2, false], [0, true]] as [$count, $more]) {
            DB::table('search_logs')->insert(['user_id' => $customer->id, 'query' => 'brake', 'result_count' => $count, 'has_more' => $more, 'results' => '[]', 'created_at' => now()]);
        }
        $this->withToken('admin')->getJson('/api/admin/searches?outcome=empty')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.username', $customer->username);
        $this->getJson('/api/admin/searches?outcome=found')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/searches')->assertOk()->assertJsonPath('total', 3);
    }

    public function test_successful_empty_search_is_recorded_with_identity_but_pages_and_failures_are_not(): void
    {
        config()->set('dolibarr.url', 'http://erp.test/api/index.php');
        config()->set('dolibarr.key', 'test-key');
        Http::fake(fn ($request) => Http::response([], str_contains($request->url(), 'failed') ? 500 : 200));
        $user = User::factory()->create(['api_token_hash' => hash('sha256', 'customer')]);
        $this->withToken('customer')->getJson('/api/products?q=brake')->assertOk();
        $this->assertDatabaseHas('search_logs', ['query' => 'brake', 'user_id' => $user->id, 'result_count' => 0, 'has_more' => false]);
        $this->getJson('/api/products?q=brake&page=1')->assertOk();
        $this->getJson('/api/products')->assertOk();
        $this->getJson('/api/products?q=invalid%21')->assertUnprocessable();
        $this->getJson('/api/products?q=failed')->assertStatus(502);
        $this->assertDatabaseCount('search_logs', 1);
    }

    public function test_briefing_uses_baghdad_day_and_groups_lost_searches(): void
    {
        $this->travelTo(Carbon::parse('2026-10-18 00:30:00', 'Asia/Baghdad'));
        User::factory()->create(['role' => 'admin', 'api_token_hash' => hash('sha256', 'admin')]);
        $this->createOrder(['status' => Order::STATUS_PAID, 'total' => 12500, 'paid_at' => now(), 'fulfilment_status' => 'delivered', 'dolibarr_synced_at' => now()]);
        $this->createOrder(['status' => Order::STATUS_PAID, 'total' => 9000, 'paid_at' => now()->subDay(), 'fulfilment_status' => 'delivered', 'dolibarr_synced_at' => now()]);
        $this->createOrder(['status' => Order::STATUS_ON_HOLD]);
        foreach (['Brake', 'brake'] as $query) {
            DB::table('search_logs')->insert(['query' => $query, 'result_count' => 0, 'has_more' => false, 'results' => '[]', 'created_at' => now()]);
        }
        $this->withToken('admin')->getJson('/api/admin/search-insights')->assertOk()
            ->assertJsonPath('data.date', '2026-10-18')->assertJsonPath('data.paid_sales', 12500)
            ->assertJsonPath('data.attention_count', 1)->assertJsonPath('data.popular_searches.0.searches', 2)
            ->assertJsonPath('data.lost_searches.0.searches', 2);
    }

    private function createOrder(array $attributes): Order
    {
        return Order::create(array_merge([
            'reference' => 'WEB-'.bin2hex(random_bytes(4)), 'access_key' => Order::newAccessKey(),
            'payment_method' => Order::METHOD_COD, 'customer_name' => 'Test',
            'customer_phone' => '07700000000', 'city' => 'Erbil', 'address_line' => 'Street',
            'subtotal' => 1000, 'total' => 1000,
        ], $attributes));
    }
}
