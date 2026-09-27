<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'OPTIONS'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('FRONTEND_ORIGINS', 'http://127.0.0.1:5173,http://localhost:5173')))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'Authorization'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
