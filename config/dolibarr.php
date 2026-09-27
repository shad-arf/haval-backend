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
     * The Dolibarr third party (socid) that web sales are booked against.
     *
     * requirements.md §2.5 asks the ERP team to choose between one third party
     * per online customer and a single generic "Web sales" customer. This is
     * the generic-customer option; leave it at 0 until they answer, because a
     * push with no customer cannot succeed.
     */
    'customer_id' => (int) env('DOLIBARR_CUSTOMER_ID', 0),

    /**
     * VAT rate sent on every pushed line. requirements.md §2.5 item 4 asks the
     * ERP team to confirm whether VAT applies to online sales.
     */
    'vat_rate' => (float) env('DOLIBARR_VAT_RATE', 0),
];
