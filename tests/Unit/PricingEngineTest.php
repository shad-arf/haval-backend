<?php

namespace Tests\Unit;

use App\Services\PricingEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PricingEngineTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function engine(array $overrides = []): PricingEngine
    {
        return new PricingEngine(array_merge([
            'profit_percentage' => 25,
            'usd_iqd_rate' => 1320,
            'rounding_increment' => 250,
            'cost_currency' => 'USD',
            'hide_out_of_stock' => true,
            'low_stock_threshold' => 5,
        ], $overrides));
    }

    public function test_it_applies_profit_then_exchange_rate_then_rounds_up(): void
    {
        // 24.01 USD * 1.25 = 30.0125 USD * 1320 = 39,616.50 IQD -> 39,750.
        $this->assertSame(39750.0, $this->engine()->sellingPrice(24.01));
    }

    public function test_prices_always_land_on_the_rounding_increment(): void
    {
        $engine = $this->engine();

        foreach ([0.01, 1.82, 4.15, 5.41, 24.01, 67.5] as $cost) {
            $price = $engine->sellingPrice($cost);

            $this->assertSame(0.0, fmod($price, 250.0), "Price for {$cost} is not a 250 multiple.");
        }
    }

    public function test_it_rounds_up_rather_than_to_nearest(): void
    {
        $engine = $this->engine();

        $this->assertSame(250.0, $engine->roundUp(1.0));
        $this->assertSame(250.0, $engine->roundUp(250.0));
        $this->assertSame(500.0, $engine->roundUp(250.01));
        $this->assertSame(0.0, $engine->roundUp(0.0));
    }

    public function test_a_negative_price_never_goes_below_zero(): void
    {
        $this->assertSame(0.0, $this->engine()->roundUp(-500.0));
    }

    public function test_a_per_product_profit_overrides_the_global_margin(): void
    {
        $engine = $this->engine();

        $this->assertSame(26500.0, $engine->sellingPrice(20.0, 0.0));
        $this->assertGreaterThan($engine->sellingPrice(20.0, 0.0), $engine->sellingPrice(20.0, 50.0));
    }

    public function test_profit_is_clamped_to_a_sane_range(): void
    {
        $engine = $this->engine();

        $this->assertSame($engine->sellingPrice(10.0, 0.0), $engine->sellingPrice(10.0, -50.0));
        $this->assertSame($engine->sellingPrice(10.0, 1000.0), $engine->sellingPrice(10.0, 5000.0));
    }

    public function test_an_iqd_cost_currency_divides_by_the_exchange_rate(): void
    {
        $this->assertSame(24.01, $this->engine()->costInUsd(24.01));
        $this->assertEqualsWithDelta(
            24.01 / 1320,
            $this->engine(['cost_currency' => 'IQD'])->costInUsd(24.01),
            0.000001,
        );
    }

    /**
     * Guards the bug inherited from the WooCommerce store, where USD costs were
     * read as IQD and every part collapsed onto the 250-IQD rounding floor.
     */
    public function test_reading_usd_costs_as_iqd_collapses_prices_onto_the_floor(): void
    {
        $asIqd = $this->engine(['cost_currency' => 'IQD']);
        $asUsd = $this->engine();

        foreach ([3.01, 4.81, 5.71, 24.01] as $cost) {
            $this->assertSame(250.0, $asIqd->sellingPrice($asIqd->costInUsd($cost)));
            $this->assertGreaterThan(250.0, $asUsd->sellingPrice($asUsd->costInUsd($cost)));
        }
    }

    public function test_it_reads_cost_price_before_falling_back_to_pmp(): void
    {
        $engine = $this->engine();

        $this->assertSame(24.01, $engine->sourceCost(['cost_price' => 24.01, 'pmp' => '20.0']));
        $this->assertSame(20.0, $engine->sourceCost(['cost_price' => '0.00000000', 'pmp' => '20.0']));
        $this->assertSame(0.0, $engine->sourceCost(['cost_price' => '0.00000000', 'pmp' => '0.00000000']));
        $this->assertSame(0.0, $engine->sourceCost([]));
    }

    public function test_per_warehouse_stock_wins_over_the_cached_total(): void
    {
        $engine = $this->engine();

        $this->assertSame(130.0, $engine->stockQuantity([
            'stock_reel' => 100,
            'stock_warehouse' => [
                '5' => ['real' => '30'],
                '6' => ['real' => '100'],
            ],
        ]));

        $this->assertSame(100.0, $engine->stockQuantity(['stock_reel' => 100, 'stock_warehouse' => []]));
        $this->assertNull($engine->stockQuantity(['ref' => 'NO-STOCK-DATA']));
    }

    #[DataProvider('stockStatuses')]
    public function test_it_classifies_stock_for_display(?float $quantity, string $expected): void
    {
        $this->assertSame($expected, $this->engine()->stockStatus($quantity));
    }

    /**
     * @return array<string, array{0: float|null, 1: string}>
     */
    public static function stockStatuses(): array
    {
        return [
            'unknown' => [null, 'out_of_stock'],
            'none' => [0.0, 'out_of_stock'],
            'negative' => [-3.0, 'out_of_stock'],
            'single unit' => [1.0, 'low_stock'],
            'just under threshold' => [4.0, 'low_stock'],
            'at threshold' => [5.0, 'in_stock'],
            'plenty' => [250.0, 'in_stock'],
        ];
    }

    public function test_a_product_without_a_cost_is_never_published(): void
    {
        $this->assertFalse($this->engine()->isPublishable(0.0, 100.0));
        $this->assertTrue($this->engine()->isPublishable(4.15, 100.0));
    }

    public function test_out_of_stock_products_are_hidden_unless_the_rule_is_disabled(): void
    {
        $this->assertFalse($this->engine()->isPublishable(4.15, 0.0));
        $this->assertFalse($this->engine()->isPublishable(4.15, null));
        $this->assertTrue($this->engine(['hide_out_of_stock' => false])->isPublishable(4.15, 0.0));
    }
}
