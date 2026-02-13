<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health Check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'ServiceBox API',
        'timestamp' => now()->toIso8601String(),
        'database' => \DB::connection()->getDatabaseName(),
    ]);
});

// ============================================================================
// AUTH API
// ============================================================================
Route::prefix('auth')->group(function () {
    // Public
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    // Protected (Bearer token)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
    });
});

// ============================================================================
// WIDGET API (Public, X-Shop-ID header, no auth)
// ============================================================================
Route::prefix('widget')->middleware('tenant')->group(function () {
    // Shop info
    Route::get('/shop', [ShopController::class, 'getPublicInfo']);

    // Products (read-only)
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);

    // Orders (widget can create orders)
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);

    // Bookings (widget can create bookings)
    Route::get('/bookings/available-slots', [BookingController::class, 'availableSlots']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::get('/bookings/{booking}', [BookingController::class, 'show']);
});

// ============================================================================
// ADMIN API (Bearer token + Shop context + Subscription check)
// ============================================================================
Route::prefix('admin')->middleware(['auth:sanctum', 'auth.shop', 'subscription'])->group(function () {
    // Shop
    Route::get('/shop', [ShopController::class, 'show']);
    Route::put('/shop', [ShopController::class, 'update']);

    // Products
    Route::apiResource('products', ProductController::class);

    // Orders
    Route::get('/orders/stats', [OrderController::class, 'stats']);
    Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show']);
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);

    // Customers
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);

    // Bookings
    Route::get('/bookings/available-slots', [BookingController::class, 'availableSlots']);
    Route::apiResource('bookings', BookingController::class)->only(['index', 'store', 'show']);
    Route::patch('/bookings/{booking}/status', [BookingController::class, 'updateStatus']);

});

// Subscription & Telegram (no subscription check — accessible even when expired)
Route::prefix('admin')->middleware(['auth:sanctum', 'auth.shop'])->group(function () {
    Route::get('/subscription', [PaymentController::class, 'getSubscriptionInfo']);
    Route::get('/subscription/payments', [PaymentController::class, 'getPaymentHistory']);
    Route::post('/subscription/create-payment', [PaymentController::class, 'createSubscriptionPayment']);

    Route::post('/telegram/generate-code', [TelegramController::class, 'generateCode']);
    Route::get('/telegram/status', [TelegramController::class, 'status']);
    Route::post('/telegram/disconnect', [TelegramController::class, 'disconnect']);
});

// ============================================================================
// WEBHOOKS (no auth, verified by middleware)
// ============================================================================
Route::prefix('webhook')->group(function () {
    Route::post('/yookassa', [PaymentController::class, 'handleYooKassaWebhook'])
        ->middleware('verify.yookassa');
    Route::post('/telegram', [TelegramController::class, 'webhook'])
        ->middleware('verify.telegram');
});
