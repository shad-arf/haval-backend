<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'Masdar ERP API',
        'status' => 'running',
        'catalog' => '/api/products',
        'connection' => '/api/erp/health',
    ]);
});
