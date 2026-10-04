<?php

return [
    'url' => env('DOLIBARR_API_URL', 'http://127.0.0.1:8000/api/index.php'),
    'key' => env('DOLIBARR_API_KEY'),
    'timeout' => (int) env('DOLIBARR_API_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Writing orders back to the ERP
    |--------------------------------------------------------------------------
    |
    | When a payment is confirmed, the order can be pushed to Dolibarr as a
    | sales order plus a validated invoice, as the WooCommerce store did.
    |
    | This writes real records into the ERP, so it is OFF by default. Turn it on
    | only once the ERP team has confirmed the numbering scheme and the API user
    | has create permission on orders, invoices and third parties.
    |
    */

    'push_orders' => (bool) env('DOLIBARR_PUSH_ORDERS', false),

    /**
     * The generic Dolibarr third party (socid, "ONLINECU") that web sales are
     * booked against. A dealer whose account carries a Dolibarr customer code
     * is booked against that customer instead — see DolibarrOrderPusher.
     * A push with no customer cannot succeed, so 0 blocks every push.
     */
    'customer_id' => (int) env('DOLIBARR_CUSTOMER_ID', 0),

    /**
     * Whether a sales order is created before the invoice. Turn off where the
     * Dolibarr Sales Orders module is disabled and the shop bills directly.
     */
    'push_sales_orders' => (bool) env('DOLIBARR_PUSH_SALES_ORDER', true),

    /**
     * VAT rate sent on every pushed line. requirements.md §2.5 item 4 asks the
     * ERP team to confirm whether VAT applies to online sales.
     */
    'vat_rate' => (float) env('DOLIBARR_VAT_RATE', 0),

    /**
     * Warehouse that validating a pushed invoice takes stock from. Dolibarr
     * moves stock on invoice validation (STOCK_CALCULATE_ON_BILL), but only
     * when it is told a warehouse; 0 validates without moving any stock.
     */
    'warehouse_id' => (int) env('DOLIBARR_WAREHOUSE_ID', 0),
];
