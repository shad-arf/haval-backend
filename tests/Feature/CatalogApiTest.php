<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dolibarr.url', 'http://erp.test/api/index.php');
        config()->set('dolibarr.key', 'test-key');
        config()->set('shop', [
            'profit_percentage' => 25,
            'usd_iqd_rate' => 1320,
            'rounding_increment' => 250,
            'cost_currency' => 'USD',
            'hide_out_of_stock' => true,
            'low_stock_threshold' => 5,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function erpProduct(array $overrides = []): array
    {
        return array_merge([
            'id' => '15752',
            'ref' => 'DS3200GS',
            'label' => 'DS3200GS',
            'status' => '1',
            'description' => 'SHOCK ABSORBER',
            'note_public' => '',
            'cost_price' => 24.01,
            'pmp' => '20.00000000',
            'price' => '30.00000000',
            'stock_reel' => 12,
        ], $overrides);
    }

    public function test_it_returns_products_priced_in_iqd(): void
    {
        Http::fake(['erp.test/*' => Http::response([$this->erpProduct()])]);

        $response = $this->getJson('/api/products?limit=1');

        $response->assertOk()
            ->assertJsonPath('currency', 'IQD')
            ->assertJsonPath('data.0.ref', 'DS3200GS')
            ->assertJsonPath('data.0.price', 39750)
            ->assertJsonPath('data.0.currency', 'IQD')
            ->assertJsonPath('data.0.stock_status', 'in_stock')
            ->assertJsonPath('data.0.is_sellable', true);
    }

    public function test_it_asks_the_erp_to_exclude_unpriceable_and_out_of_stock_parts(): void
    {
        Http::fake(['erp.test/*' => Http::response([])]);

        $this->getJson('/api/products')->assertOk();

        Http::assertSent(function (Request $request): bool {
            $filters = $request->data()['sqlfilters'] ?? '';

            return str_contains($filters, '(t.cost_price:>:0)')
                && str_contains($filters, '(t.stock:>:0)');
        });
    }

    public function test_a_search_term_is_sent_to_the_erp_as_a_ref_or_label_filter(): void
    {
        Http::fake(['erp.test/*' => Http::response([])]);

        $this->getJson('/api/products?q=BS012')->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains(
            $request->data()['sqlfilters'] ?? '',
            "((t.ref:like:'%BS012%') or (t.label:like:'%BS012%'))",
        ));
    }

    public function test_a_brand_is_sent_to_the_erp_as_a_category(): void
    {
        Http::fake(['erp.test/*' => Http::response([])]);

        $this->getJson('/api/products?brand=10')->assertOk();

        Http::assertSent(fn (Request $request): bool => ($request->data()['category'] ?? null) == 10);
    }

    public function test_quote_characters_cannot_break_out_of_the_erp_filter(): void
    {
        Http::fake(['erp.test/*' => Http::response([])]);

        // Rejected by validation before it can reach the ERP at all.
        $this->getJson('/api/products?q='.urlencode("a') or (1:=:1"))->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_it_hides_a_part_the_erp_reports_as_out_of_stock(): void
    {
        Http::fake(['erp.test/*' => Http::response([
            $this->erpProduct(['stock_reel' => 0, 'stock_warehouse' => []]),
        ])]);

        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_it_hides_a_part_that_has_no_cost_to_price_from(): void
    {
        Http::fake(['erp.test/*' => Http::response([
            $this->erpProduct(['cost_price' => 0, 'pmp' => '0.00000000']),
        ])]);

        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_low_stock_is_flagged_separately_from_in_stock(): void
    {
        Http::fake(['erp.test/*' => Http::response([$this->erpProduct(['stock_reel' => 3])])]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.stock_status', 'low_stock');
    }

    public function test_it_prefers_a_real_name_over_a_label_that_repeats_the_reference(): void
    {
        Http::fake(['erp.test/*' => Http::response([
            $this->erpProduct(['label' => 'DS3200GS', 'note_public' => 'SHOCK ABSORBER']),
        ])]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'SHOCK ABSORBER');
    }

    public function test_a_genuine_label_is_used_as_the_name(): void
    {
        Http::fake(['erp.test/*' => Http::response([
            $this->erpProduct(['label' => 'بوش طبلة اسفل رام']),
        ])]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'بوش طبلة اسفل رام');
    }

    public function test_a_part_with_no_usable_text_falls_back_to_its_reference(): void
    {
        Http::fake(['erp.test/*' => Http::response([
            $this->erpProduct(['label' => 'DS3200GS', 'note_public' => '', 'description' => '']),
        ])]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'DS3200GS');
    }

    public function test_has_more_is_false_once_a_short_page_comes_back(): void
    {
        Http::fake(['erp.test/*' => Http::response([$this->erpProduct()])]);

        $this->getJson('/api/products?limit=50')
            ->assertOk()
            ->assertJsonPath('has_more', false);
    }

    /**
     * Every visitor asks for the same first pages, and each one costs the ERP
     * the better part of a second.
     */
    public function test_a_catalog_page_is_fetched_from_the_erp_once_a_minute(): void
    {
        Http::fake(['erp.test/*' => Http::response([$this->erpProduct()])]);
        $catalogRequests = fn (): int => Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/products?'))->count();

        $this->getJson('/api/products?limit=1')->assertOk();
        $this->getJson('/api/products?limit=1')->assertOk();
        $this->assertSame(1, $catalogRequests());

        $this->travel(61)->seconds();
        $this->getJson('/api/products?limit=1')->assertOk();
        $this->assertSame(2, $catalogRequests());
    }

    /**
     * Carts fetch parts one by one; repeated lookups (several tabs, a busy
     * cart) must not each reach the ERP.
     */
    public function test_a_single_product_is_fetched_from_the_erp_once_per_30_seconds(): void
    {
        Http::fake(['erp.test/*' => Http::response($this->erpProduct())]);

        $this->getJson('/api/products/15752')->assertOk();
        $this->getJson('/api/products/15752')->assertOk();
        Http::assertSentCount(1);

        $this->travel(31)->seconds();
        $this->getJson('/api/products/15752')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_a_single_product_is_priced_the_same_way_as_the_listing(): void
    {
        Http::fake(['erp.test/*' => Http::response($this->erpProduct())]);

        $this->getJson('/api/products/15752')
            ->assertOk()
            ->assertJsonPath('price', 39750)
            ->assertJsonPath('ref', 'DS3200GS');
    }

    public function test_a_hidden_product_is_not_reachable_by_id(): void
    {
        Http::fake(['erp.test/*' => Http::response($this->erpProduct(['stock_reel' => 0]))]);

        $this->getJson('/api/products/15752')->assertNotFound();
    }

    public function test_brands_come_from_product_categories(): void
    {
        Http::fake(['erp.test/*' => Http::response([
            ['id' => '10', 'label' => 'TEKNOROT'],
            ['id' => '11', 'label' => 'KAUTEK'],
        ])]);

        $this->getJson('/api/brands')
            ->assertOk()
            ->assertJsonPath('data.0', ['id' => 10, 'name' => 'TEKNOROT'])
            ->assertJsonPath('data.1', ['id' => 11, 'name' => 'KAUTEK']);
    }

    public function test_an_erp_outage_is_reported_as_service_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('offline'));

        $this->getJson('/api/products')->assertStatus(503);
    }

    public function test_an_erp_error_is_not_leaked_to_the_browser(): void
    {
        Http::fake(['erp.test/*' => Http::response(['error' => 'SQL syntax'], 500)]);

        $this->getJson('/api/products')
            ->assertStatus(502)
            ->assertJsonPath('message', 'The catalog request failed.');
    }

    public function test_an_oversized_page_is_rejected(): void
    {
        Http::fake(['erp.test/*' => Http::response([])]);

        $this->getJson('/api/products?limit=5000')->assertStatus(422);
    }
}
