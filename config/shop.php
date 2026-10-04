<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Storefront pricing
    |--------------------------------------------------------------------------
    |
    | The ERP stores a cost per product. The storefront sells in IQD, so a
    | selling price is derived as:
    |
    |     cost -> apply profit % -> convert to IQD -> round up to increment
    |
    | These values mirror the WooCommerce store this API replaces.
    |
    */

    'profit_percentage' => (float) env('SHOP_PROFIT_PERCENTAGE', 25),

    /*
     * Dealers pay cost + this profit, worked up from cost — not a discount off
     * the customer price (25% off a 50%-profit price would be ~12.5% profit).
     * A dealer's own dealer_profit_percentage overrides it.
     */
    'dealer_profit_percentage' => (float) env('SHOP_DEALER_PROFIT_PERCENTAGE', 25),

    'usd_iqd_rate' => (float) env('SHOP_USD_IQD_RATE', 1320),

    /**
     * Selling prices are restricted to 250-IQD increments (250, 500, 750, ...).
     */
    'rounding_increment' => (float) env('SHOP_ROUNDING_INCREMENT', 250),

    /**
     * Currency the ERP cost is expressed in, either 'USD' or 'IQD'.
     *
     * The Dolibarr instance stores costs in USD (values run from 0.01 to about
     * 67.50 per part). The WooCommerce store had this set to IQD, which divided
     * every cost by the exchange rate and collapsed the whole catalog onto the
     * 250-IQD rounding floor. Keep this on USD unless the ERP changes.
     */
    'cost_currency' => env('SHOP_COST_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Stock rules
    |--------------------------------------------------------------------------
    */

    /**
     * Products at or below zero stock are not published to the storefront.
     */
    'hide_out_of_stock' => (bool) env('SHOP_HIDE_OUT_OF_STOCK', true),

    /**
     * Stock below this is shown as "low stock" rather than a bare quantity.
     */
    'low_stock_threshold' => (float) env('SHOP_LOW_STOCK_THRESHOLD', 5),

    /*
    |--------------------------------------------------------------------------
    | Site content
    |--------------------------------------------------------------------------
    |
    | Contact details and short marketing copy shown across the storefront
    | (top bar, footer, contact page, invoices). Editable from the admin
    | dashboard (see App\Support\SiteSettings); these are just the defaults
    | that show before an admin overrides them in shop_settings.
    |
    | The three text fields default to '', which tells the storefront to
    | fall back to its own translated copy. An admin-entered value is shown
    | verbatim in every language once set.
    */

    'phone_number' => env('SHOP_PHONE_NUMBER', '+964 782 450 0404'),

    'whatsapp_number' => env('SHOP_WHATSAPP_NUMBER', '9647824500404'),

    'free_delivery_text' => env('SHOP_FREE_DELIVERY_TEXT', ''),

    'trusted_since_text' => env('SHOP_TRUSTED_SINCE_TEXT', ''),

    'store_address' => env('SHOP_STORE_ADDRESS', ''),

    'store_hours' => env('SHOP_STORE_HOURS', ''),
];
