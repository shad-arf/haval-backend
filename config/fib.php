<?php

return [
    /*
    |--------------------------------------------------------------------------
    | FIB Cards Payment Gateway (ACS24)
    |--------------------------------------------------------------------------
    |
    | Visa, Mastercard and other cards. Server-to-server and redirect-based:
    | the store calls FIB, FIB returns a hosted card-entry / 3-D Secure URL, and
    | the customer completes payment there. Card data never reaches this server.
    |
    */

    'enabled' => (bool) env('FIB_ENABLED', true),

    /**
     * UAT or production. Stay on UAT until FIB provisions the merchant account
     * and issues production credentials.
     */
    'environment' => env('FIB_ENVIRONMENT', 'uat'),

    /**
     * The /ACS24 path prefix is part of the base URL — requests fail without it.
     */
    'uat_base_url' => env('FIB_UAT_BASE_URL', ''),

    /** Issued by FIB with the production credentials. Keep the /ACS24 suffix. */
    'production_base_url' => env('FIB_PRODUCTION_BASE_URL', ''),

    /**
     * Merchant credentials are supplied through the environment.
     */
    'terminal_number' => env('FIB_TERMINAL_NUMBER', ''),
    'institution' => env('FIB_INSTITUTION', ''),
    'merchant_id' => env('FIB_MERCHANT_ID', ''),
    'acquirer' => env('FIB_ACQUIRER', ''),

    /**
     * Signs every request. Server-side only — it must never appear in browser
     * or client code, and it is deliberately not committed.
     */
    'hmac_secret' => env('FIB_HMAC_SECRET', ''),

    'timeout' => (int) env('FIB_TIMEOUT', 30),

    /**
     * Where FIB sends its server-to-server callback. FIB cannot reach
     * localhost, so during local development the callback simply never
     * arrives — payments still complete, because the status check is what
     * actually confirms them.
     */
    'callback_url' => env('FIB_CALLBACK_URL', ''),

    /** Where the customer's browser is sent back to after the card page. */
    'storefront_url' => env('STOREFRONT_URL', ''),

    'log' => (bool) env('FIB_LOG', true),
];
