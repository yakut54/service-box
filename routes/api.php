<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CustomerController;
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
});
