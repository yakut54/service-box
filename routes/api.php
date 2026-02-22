<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\WidgetPhoneVerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API + WEB Routes
|--------------------------------------------------------------------------
*/

// Главная страница (временная заглушка)
Route::get('/', function () {
    return view('welcome');
});

// Health Check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'ServiceBox API v2', // ← тут меняем
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

    // Categories (read-only, only visible)
    Route::get('/categories', [CategoryController::class, 'widgetIndex']);

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

    // Discount / promo code validation (widget)
    Route::post('/discount/validate', [DiscountController::class, 'widgetValidate']);
    Route::post('/discount/auto-apply', [DiscountController::class, 'widgetAutoApply']);

    // Reviews (widget)
    Route::get('/reviews/{productId}', [ReviewController::class, 'widgetIndex']);
    Route::post('/reviews', [ReviewController::class, 'widgetStore']);

    // Phone verification (OTP)
    Route::post('/phone/request-code', [WidgetPhoneVerificationController::class, 'requestCode'])
        ->middleware('rate.phone');
    Route::post('/phone/verify', [WidgetPhoneVerificationController::class, 'verify'])
        ->middleware('rate.phone');

    // Phone-protected lookups (require verified phone token)
    Route::middleware('verify.phone')->group(function () {
        Route::get('/orders', [OrderController::class, 'widgetOrdersByPhone']);
        Route::get('/bookings', [BookingController::class, 'widgetBookingsByPhone']);
        Route::patch('/bookings/{booking}/cancel', [BookingController::class, 'widgetCancel']);
    });
});

// ============================================================================
// ADMIN API (Bearer token + Shop context + Subscription check)
// ============================================================================
Route::prefix('admin')->middleware(['auth:sanctum', 'auth.shop', 'subscription'])->group(function () {
    // Shop
    Route::get('/shop', [ShopController::class, 'show']);
    Route::put('/shop', [ShopController::class, 'update']);

    // Categories (reorder must be before {id} routes)
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::patch('/categories/reorder', [CategoryController::class, 'reorder']);
    Route::patch('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

    // Products
    Route::apiResource('products', ProductController::class);

    // Orders
    Route::get('/orders/stats', [OrderController::class, 'stats']);
    Route::get('/orders/chart', [OrderController::class, 'chart']);
    Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);

    // Customers
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);

    // Image upload
    Route::post('/upload/image', [ImageController::class, 'upload']);

    // Bookings
    Route::get('/bookings/stats', [BookingController::class, 'adminStats']);
    Route::get('/bookings/masters', [BookingController::class, 'masters']);
    Route::get('/bookings/available-slots', [BookingController::class, 'availableSlots']);
    Route::apiResource('bookings', BookingController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::patch('/bookings/{booking}', [BookingController::class, 'updateStatus']);

    // Masters
    Route::apiResource('masters', MasterController::class);

    // Discounts
    Route::apiResource('discounts', DiscountController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::patch('/discounts/{id}', [DiscountController::class, 'update']);

    // Reviews
    Route::get('/reviews', [ReviewController::class, 'index']);
    Route::patch('/reviews/{id}', [ReviewController::class, 'update']);
    Route::delete('/reviews/{id}', [ReviewController::class, 'destroy']);

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
