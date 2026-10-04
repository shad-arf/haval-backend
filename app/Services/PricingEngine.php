<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Derives the storefront selling price in IQD from an ERP cost.
 *
 * This ports the pricing rules of the WooCommerce store: the ERP cost has a
 * profit margin applied, is converted to IQD, and is then rounded up to a fixed
 * increment so every shelf price lands on a round number.
 */
class PricingEngine
{
    private readonly float $profitPercentage;

    private readonly float $usdIqdRate;

    private readonly float $dealerProfitPercentage;

    private readonly float $roundingIncrement;

    private readonly string $costCurrency;

    private readonly float $lowStockThreshold;

    private readonly bool $hideOutOfStock;

    public function __construct(?array $settings = null)
    {
        if ($settings === null) {
            $settings = array_merge(config('shop'), DB::table('shop_settings')->pluck('value', 'key')->all());
        }

        $this->profitPercentage = max(0.0, min(1000.0, (float) ($settings['profit_percentage'] ?? 25)));
        $this->usdIqdRate = max(1.0, (float) ($settings['usd_iqd_rate'] ?? 1320));
        $this->dealerProfitPercentage = max(0.0, min(1000.0, (float) ($settings['dealer_profit_percentage'] ?? 25)));
        $this->roundingIncrement = max(1.0, (float) ($settings['rounding_increment'] ?? 250));
        $this->costCurrency = strtoupper((string) ($settings['cost_currency'] ?? 'USD')) === 'IQD' ? 'IQD' : 'USD';
        $this->lowStockThreshold = max(0.0, (float) ($settings['low_stock_threshold'] ?? 5));
        $this->hideOutOfStock = (bool) ($settings['hide_out_of_stock'] ?? true);
    }

    /**
     * An IQD shop amount in the ERP's own currency, for writing documents
     * back to Dolibarr. Its books are kept in the cost currency, so a USD ERP
     * gets the amount converted at the shop's rate.
     */
    public function toErpCurrency(float $iqd): float
    {
        return $this->costCurrency === 'IQD' ? $iqd : round($iqd / $this->usdIqdRate, 2);
    }

    public function usdIqdRate(): float
    {
        return $this->usdIqdRate;
    }

    public function erpCurrency(): string
    {
        return $this->costCurrency;
    }

    /**
     * Read the ERP cost from a Dolibarr product payload.
     *
     * Dolibarr fills `cost_price` on every product here, but older records only
     * carry the weighted average price, so fall back the same way the
     * WooCommerce importer did.
     *
     * @param  array<string, mixed>  $product
     */
    public function sourceCost(array $product): float
    {
        foreach (['cost_price', 'cost', 'pmp'] as $field) {
            $value = (float) ($product[$field] ?? 0);

            if ($value > 0) {
                return $value;
            }
        }

        return 0.0;
    }

    /**
     * Normalise an ERP cost to USD, whatever currency the ERP records it in.
     */
    public function costInUsd(float $sourceCost): float
    {
        return $this->costCurrency === 'IQD' ? $sourceCost / $this->usdIqdRate : $sourceCost;
    }

    /**
     * The shelf price in IQD for a USD cost.
     *
     * @param  float|null  $profitPercentage  Per-product override of the global margin.
     */
    public function sellingPrice(float $costUsd, ?float $profitPercentage = null): float
    {
        $profit = $profitPercentage === null
            ? $this->profitPercentage
            : max(0.0, min(1000.0, $profitPercentage));

        $usdSellingPrice = $costUsd * (1 + ($profit / 100));

        return $this->roundUp($usdSellingPrice * $this->usdIqdRate);
    }

    /**
     * Round a price up to the configured increment, never below zero.
     */
    /**
     * What a dealer pays: cost + dealer profit, rounded like every shelf price.
     * Never more than the customer price, so a part whose own profit override
     * is below the dealer profit simply sells to dealers at the customer price.
     */
    public function dealerPrice(float $costUsd, ?float $customerProfitOverride, ?float $dealerProfit = null): float
    {
        return min(
            $this->sellingPrice($costUsd, $dealerProfit ?? $this->dealerProfitPercentage),
            $this->sellingPrice($costUsd, $customerProfitOverride),
        );
    }

    /**
     * An admin sale price, when it actually undercuts the regular price —
     * otherwise null, so a stale sale above a lowered price is ignored.
     */
    public function activeSalePrice(?int $salePrice, float $regularPrice): ?int
    {
        return $salePrice !== null && $salePrice > 0 && $salePrice < $regularPrice ? $salePrice : null;
    }

    /**
     * What a shopper pays for one unit: the regular (or sale) price, or for a
     * dealer the lower of their dealer price and any sale price.
     */
    public function payablePrice(float $costUsd, ?float $profitOverride, ?int $salePrice, ?float $dealerProfit): int
    {
        $regular = $this->sellingPrice($costUsd, $profitOverride);
        $price = $this->activeSalePrice($salePrice, $regular) ?? $regular;

        if ($dealerProfit !== null) {
            $price = min($price, $this->dealerPrice($costUsd, $profitOverride, $dealerProfit));
        }

        return (int) $price;
    }

    public function dealerProfitPercentage(): float
    {
        return $this->dealerProfitPercentage;
    }

    public function roundUp(float $price): float
    {
        return ceil(max(0.0, $price) / $this->roundingIncrement) * $this->roundingIncrement;
    }

    /**
     * Read the saleable stock level from a Dolibarr product payload.
     *
     * The list endpoint returns `stock_reel` when queried with
     * `includestockdata`, but per-warehouse data is authoritative when present.
     *
     * @param  array<string, mixed>  $product
     */
    public function stockQuantity(array $product): ?float
    {
        if (is_array($product['stock_warehouse'] ?? null) && $product['stock_warehouse'] !== []) {
            $total = 0.0;

            foreach ($product['stock_warehouse'] as $warehouse) {
                $total += (float) ($warehouse['real'] ?? 0);
            }

            return $total;
        }

        foreach (['stock', 'stock_reel', 'stock_physique'] as $field) {
            if (isset($product[$field])) {
                return (float) $product[$field];
            }
        }

        return null;
    }

    /**
     * Classify stock for display: `in_stock`, `low_stock`, or `out_of_stock`.
     */
    public function stockStatus(?float $quantity): string
    {
        if ($quantity === null || $quantity <= 0) {
            return 'out_of_stock';
        }

        return $quantity < $this->lowStockThreshold ? 'low_stock' : 'in_stock';
    }

    /**
     * Whether a product may appear in the storefront at all.
     *
     * A product with no cost cannot be priced, and out-of-stock parts are
     * hidden rather than shown as unavailable.
     */
    public function isPublishable(float $sourceCost, ?float $quantity): bool
    {
        if ($sourceCost <= 0) {
            return false;
        }

        return ! $this->hideOutOfStock || ($quantity !== null && $quantity > 0);
    }

    public function lowStockThreshold(): float
    {
        return $this->lowStockThreshold;
    }

    public function hidesOutOfStock(): bool
    {
        return $this->hideOutOfStock;
    }
}
