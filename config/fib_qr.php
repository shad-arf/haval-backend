<?php

return [
    'enabled' => (bool) env('FIB_QR_ENABLED', true),
    'base_url' => env('FIB_QR_BASE_URL', ''),
    'client_id' => env('FIB_QR_CLIENT_ID', ''),
    'client_secret' => env('FIB_QR_CLIENT_SECRET', ''),
    'callback_url' => env('FIB_QR_CALLBACK_URL', ''),
    'timeout' => (int) env('FIB_QR_TIMEOUT', 20),
];
