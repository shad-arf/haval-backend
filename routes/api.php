<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdminShopController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\ErpProductController;
use App\Http\Controllers\Api\FibPaymentController;
use App\Http\Controllers\Api\FibQrPaymentController;
use App\Http\Controllers\Api\ProductImageController;
use App\Http\Controllers\Api\SearchLogController;
use App\Http\Controllers\Api\SiteSettingsController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AccountController::class, 'register']);
Route::post('/login', [AccountController::class, 'login']);
Route::get('/account', [AccountController::class, 'me']);
Route::patch('/account', [AccountController::class, 'updateProfile']);
Route::post('/logout', [AccountController::class, 'logout']);
Route::get('/account/orders', [AccountController::class, 'orders']);
Route::get('/admin/orders', [AccountController::class, 'adminOrders']);
Route::get('/admin/summary', [AdminShopController::class, 'summary']);
Route::get('/admin/users', [AccountController::class, 'adminUsers']);
Route::patch('/admin/users/{user}', [AccountController::class, 'updateUser']);
Route::post('/admin/users', [AccountController::class, 'createUser']);
Route::post('/admin/users/{user}/password', [AccountController::class, 'resetPassword']);
Route::get('/admin/products', [AdminShopController::class, 'products']);
Route::get('/admin/products/{id}', [AdminShopController::class, 'showProduct'])->whereNumber('id');
Route::patch('/admin/products/{id}', [AdminShopController::class, 'updateProduct'])->whereNumber('id');
Route::get('/admin/orders/{order:reference}', [AdminShopController::class, 'showOrder']);
Route::patch('/admin/orders/{order:reference}', [AdminShopController::class, 'updateOrder']);
Route::get('/admin/orders/{order:reference}/invoice', [AdminShopController::class, 'orderInvoice']);
Route::post('/admin/orders/{order:reference}/sync-erp', [AdminShopController::class, 'retryErpSync']);
Route::post('/admin/orders/{order:reference}/returns', [AdminShopController::class, 'createReturn']);
Route::get('/admin/settings', [AdminShopController::class, 'settings']);
Route::patch('/admin/settings', [AdminShopController::class, 'updateSettings']);

// Site content — public read of contact info and homepage copy.
Route::get('/settings', [SiteSettingsController::class, 'index']);

// Catalog — read-only, straight through to Dolibarr.
Route::get('/erp/health', [ErpProductController::class, 'health']);
Route::get('/brands', [ErpProductController::class, 'brands']);
Route::get('/brands/{id}/image', [ProductImageController::class, 'brand'])
    ->name('brands.image')
    ->whereNumber('id');
Route::get('/products', [ErpProductController::class, 'index']);
Route::get('/products/ref/{ref}', [ErpProductController::class, 'byRef']);
Route::get('/products/{id}', [ErpProductController::class, 'show'])->whereNumber('id');

// Product photos, proxied out of the ERP document store so the browser never
// needs the Dolibarr API key.
Route::get('/products/ref/{ref}/image', [ProductImageController::class, 'show'])
    ->name('products.image')
    ->where('ref', '[A-Za-z0-9._\\-]+');

// Checkout and orders. An order is read back with its access key, which stands
// in for a customer login.
Route::get('/payment-methods', [CheckoutController::class, 'paymentMethods']);
Route::post('/checkout', [CheckoutController::class, 'store']);
Route::get('/orders/{reference}', [CheckoutController::class, 'show']);
Route::get('/orders/{reference}/status', [CheckoutController::class, 'status']);
Route::get('/orders/{reference}/invoice', [CheckoutController::class, 'invoice']);
Route::post('/orders/{reference}/pay', [CheckoutController::class, 'retryPayment']);
Route::post('/orders/{reference}/abandon', [CheckoutController::class, 'abandon']);

// Gateway entry points. Neither is trusted as evidence of payment.
Route::post('/payments/fib/callback', [FibPaymentController::class, 'callback']);
Route::post('/payments/fib-qr/callback', [FibQrPaymentController::class, 'callback']);
Route::get('/payments/fib/return/{reference}', [FibPaymentController::class, 'return'])
    ->name('payments.fib.return');

Route::get('/admin/searches', [SearchLogController::class, 'index']);

Route::get('/admin/search-insights', [SearchLogController::class, 'insights']);
