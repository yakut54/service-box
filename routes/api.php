<?php

use App\Http\Controllers\Superadmin\SuperadminShopController;
use App\Http\Controllers\Superadmin\SuperadminPricingController;
use App\Http\Controllers\Superadmin\SuperadminRevenueController;
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
use App\Http\Controllers\Widget\AnalyticsController as WidgetAnalyticsController;
use App\Http\Controllers\MaxController;
use App\Http\Controllers\DeliverySettingsController;
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

// Страница реквизитов (требуется для верификации платёжных систем)
Route::get('/kontakty', function () {
    return view('kontakty');
});

// Health Check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Список магазинов для демо-страницы (публичный)
Route::get('/shops', [ShopController::class, 'index']);

// Публичные тарифы (для страницы подписки)
Route::get('/pricing', [SuperadminPricingController::class, 'index']);

// ============================================================================
// AUTH API
// ============================================================================
Route::prefix('auth')->group(function () {
    // Public
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

    // Protected (Bearer token)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });
});

// ============================================================================
// WIDGET API (Public, X-Shop-ID header, no auth)
// ============================================================================
Route::prefix('widget')->middleware(['tenant', 'widget.subscription'])->group(function () {
    // Shop info
    Route::get('/shop', [ShopController::class, 'getPublicInfo']);

    // Categories (read-only, only visible)
    Route::get('/categories', [CategoryController::class, 'widgetIndex']);

    // Products (read-only)
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);

    // Orders (widget can create orders)
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/payment', [PaymentController::class, 'createOrderPayment'])->middleware('throttle:10,1');

    // Bookings (widget can create bookings)
    Route::get('/bookings/available-slots', [BookingController::class, 'availableSlots']);
    Route::post('/bookings', [BookingController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/bookings/{booking}', [BookingController::class, 'show']);
    Route::post('/bookings/{booking}/payment', [PaymentController::class, 'createBookingPayment'])->middleware('throttle:10,1');

    // Discount / promo code validation (widget)
    Route::post('/discount/validate', [DiscountController::class, 'widgetValidate'])->middleware('throttle:30,1');
    Route::post('/discount/auto-apply', [DiscountController::class, 'widgetAutoApply'])->middleware('throttle:30,1');

    // Reviews (widget)
    Route::get('/reviews/{productId}', [ReviewController::class, 'widgetIndex']);
    Route::post('/reviews', [ReviewController::class, 'widgetStore'])->middleware('throttle:5,1');

    // Widget analytics (fire-and-forget, public)
    Route::post('/analytics', [WidgetAnalyticsController::class, 'store'])->middleware('throttle:120,1');

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

    // Widget analytics funnel (Pro)
    Route::get('/widget/analytics', [WidgetAnalyticsController::class, 'funnel']);

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
    Route::get('/orders/export', [OrderController::class, 'export']);
    Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);

    // Customers
    Route::get('/customers/export', [CustomerController::class, 'export']);
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);

    // Image upload / delete
    Route::post('/upload/image', [ImageController::class, 'upload']);
    Route::delete('/upload/image', [ImageController::class, 'delete']);

    // Bookings
    Route::get('/bookings/stats', [BookingController::class, 'adminStats']);
    Route::get('/bookings/masters', [BookingController::class, 'masters']);
    Route::get('/bookings/available-slots', [BookingController::class, 'availableSlots']);
    Route::apiResource('bookings', BookingController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::patch('/bookings/{booking}', [BookingController::class, 'updateStatus']);

    // Masters
    Route::apiResource('masters', MasterController::class);
    Route::get('/masters/{master}/services',  [MasterController::class, 'getServices']);
    Route::put('/masters/{master}/services',  [MasterController::class, 'syncServices']);

    // Discounts
    Route::apiResource('discounts', DiscountController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::patch('/discounts/{id}', [DiscountController::class, 'update']);

    // Reviews
    Route::get('/reviews', [ReviewController::class, 'index']);
    Route::patch('/reviews/{id}', [ReviewController::class, 'update']);
    Route::delete('/reviews/{id}', [ReviewController::class, 'destroy']);

    // Delivery settings
    Route::get('/delivery-settings',  [DeliverySettingsController::class, 'show']);
    Route::put('/delivery-settings',  [DeliverySettingsController::class, 'update']);

});

// Subscription & Telegram (no subscription check — accessible even when expired)
Route::prefix('admin')->middleware(['auth:sanctum', 'auth.shop'])->group(function () {
    Route::get('/subscription', [PaymentController::class, 'getSubscriptionInfo']);
    Route::get('/subscription/payments', [PaymentController::class, 'getPaymentHistory']);
    Route::post('/subscription/create-payment', [PaymentController::class, 'createSubscriptionPayment']);

    Route::post('/telegram/generate-code', [TelegramController::class, 'generateCode']);
    Route::get('/telegram/status', [TelegramController::class, 'status']);
    Route::post('/telegram/disconnect', [TelegramController::class, 'disconnect']);

    Route::post('/max/generate-code', [MaxController::class, 'generateCode']);
    Route::get('/max/status', [MaxController::class, 'status']);
    Route::post('/max/disconnect', [MaxController::class, 'disconnect']);
});

// ============================================================================
// EXTERNAL API v1 (X-API-Key, Pro plan only)
// ============================================================================
Route::prefix('v1')->middleware(['api.auth', 'api.ratelimit', 'api.pro'])->group(function () {
    Route::get('/ping', [\App\Http\Controllers\Api\PingController::class, 'ping']);
});

// ============================================================================
// WEBHOOKS (no auth, verified by middleware)
// ============================================================================
Route::prefix('webhook')->group(function () {
    Route::post('/yookassa', [PaymentController::class, 'handleYooKassaWebhook'])
        ->middleware('verify.yookassa');
    Route::post('/telegram', [TelegramController::class, 'webhook'])
        ->middleware('verify.telegram');
    Route::post('/max/{secret}', [MaxController::class, 'webhook'])
        ->middleware('verify.max');
});


// ============================================================================
// SUPERADMIN (platform owner only)
// ============================================================================
Route::prefix('superadmin')->middleware(['auth:sanctum', 'superadmin'])->group(function () {
    // Shops
    Route::get('/shops',              [SuperadminShopController::class, 'index']);
    Route::get('/shops/{id}',         [SuperadminShopController::class, 'show']);
    Route::patch('/shops/{id}/plan',  [SuperadminShopController::class, 'updatePlan']);
    Route::get('/shops/{id}/cascade-debug', [SuperadminShopController::class, 'cascadeDebug']);

    // Revenue
    Route::get('/revenue',            [SuperadminRevenueController::class, 'index']);

    // Pricing
    Route::get('/pricing',            [SuperadminPricingController::class, 'index']);
    Route::put('/pricing',            [SuperadminPricingController::class, 'update']);
});
