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
use App\Http\Controllers\CustomerAddressController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Widget\AnalyticsController as WidgetAnalyticsController;
use App\Http\Controllers\MaxController;
use App\Http\Controllers\DeliverySettingsController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\MasterPortalController;
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
    // Must be registered before /orders/{order} below — otherwise "mine" is
    // captured as the {order} wildcard instead of matching this route.
    Route::get('/orders/mine', [OrderController::class, 'widgetOrdersMine'])
        ->middleware('verify.phone.session');
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

    // Saved addresses (mobile app "stay logged in" session — 60 days,
    // separate from the 30-min verify.phone token used above)
    Route::middleware('verify.phone.session')->group(function () {
        Route::get('/addresses', [CustomerAddressController::class, 'index']);
        Route::post('/addresses', [CustomerAddressController::class, 'store']);
        Route::put('/addresses/{address}/default', [CustomerAddressController::class, 'setDefault']);
        Route::delete('/addresses/{address}', [CustomerAddressController::class, 'destroy']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
    });
});

// ============================================================================
// ADMIN API (Bearer token + Shop context + Subscription check)
// ============================================================================
Route::prefix('admin')->middleware(['auth:sanctum', 'auth.shop', 'subscription', 'not.master'])->group(function () {
    // Shop (owner only)
    Route::get('/shop', [ShopController::class, 'show']);
    Route::put('/shop', [ShopController::class, 'update'])->middleware('owner');
    Route::post('/api-key/regenerate', [ShopController::class, 'regenerateApiKey'])->middleware('owner');

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

    // Delivery settings (owner only for write)
    Route::get('/delivery-settings', [DeliverySettingsController::class, 'show']);
    Route::put('/delivery-settings',  [DeliverySettingsController::class, 'update'])->middleware('owner');

    // Staff management (owner only)
    Route::get('/staff',              [StaffController::class, 'index'])->middleware('owner');
    Route::post('/staff',             [StaffController::class, 'store'])->middleware(['owner', 'throttle:5,1']);
    Route::put('/staff/{id}',         [StaffController::class, 'update'])->middleware('owner');
    Route::post('/staff/{id}/resend', [StaffController::class, 'resend'])->middleware(['owner', 'throttle:5,1']);
    Route::delete('/staff/{id}',      [StaffController::class, 'destroy'])->middleware('owner');

});

// Subscription & Telegram (no subscription check — accessible even when expired)
Route::prefix('admin')->middleware(['auth:sanctum', 'auth.shop', 'not.master'])->group(function () {
    // Subscription (owner only)
    Route::get('/subscription',                [PaymentController::class, 'getSubscriptionInfo'])->middleware('owner');
    Route::get('/subscription/payments',       [PaymentController::class, 'getPaymentHistory'])->middleware('owner');
    Route::post('/subscription/create-payment',[PaymentController::class, 'createSubscriptionPayment'])->middleware('owner');

    // Telegram (owner only)
    Route::post('/telegram/generate-code', [TelegramController::class, 'generateCode'])->middleware('owner');
    Route::get('/telegram/status',         [TelegramController::class, 'status'])->middleware('owner');
    Route::post('/telegram/disconnect',    [TelegramController::class, 'disconnect'])->middleware('owner');

    // MAX (owner only)
    Route::post('/max/generate-code', [MaxController::class, 'generateCode'])->middleware('owner');
    Route::get('/max/status',         [MaxController::class, 'status'])->middleware('owner');
    Route::post('/max/disconnect',    [MaxController::class, 'disconnect'])->middleware('owner');
});

// ============================================================================
// MASTER PORTAL (master role only — own messenger connection)
// ============================================================================
Route::prefix('master')->middleware(['auth:sanctum', 'auth.shop', 'subscription'])->group(function () {
    Route::get('/messenger-status',  [MasterPortalController::class, 'messengerStatus']);
    Route::get('/telegram-link',     [MasterPortalController::class, 'generateTelegramLink']);
    Route::post('/max-code',         [MasterPortalController::class, 'generateMaxCode']);
    Route::delete('/telegram',       [MasterPortalController::class, 'disconnectTelegram']);
    Route::delete('/max',            [MasterPortalController::class, 'disconnectMax']);
    Route::get('/stats',             [MasterPortalController::class, 'stats']);
});

// ============================================================================
// INVITE (public — no auth required)
// ============================================================================
Route::prefix('invite')->middleware('throttle:10,1')->group(function () {
    Route::get('/{token}',   [InviteController::class, 'show']);
    Route::post('/accept',   [InviteController::class, 'accept']);
});

// ============================================================================
// EXTERNAL API v1 (X-API-Key, Pro plan only)
// ============================================================================

// CORS preflight — без auth, чтобы браузер мог сделать OPTIONS до основного запроса
Route::prefix('v1')->middleware('api.cors')->group(function () {
    Route::options('{any}', fn () => response('', 200))->where('any', '.*');
});

Route::prefix('v1')->middleware(['api.cors', 'force.json', 'api.auth', 'api.ratelimit', 'api.pro'])->group(function () {
    Route::get('/ping', [\App\Http\Controllers\Api\PingController::class, 'ping']);

    // Bookings
    Route::get('/bookings',               [\App\Http\Controllers\Api\DataController::class,  'bookings']);
    Route::get('/bookings/{id}',          [\App\Http\Controllers\Api\DataController::class,  'booking']);
    Route::post('/bookings',              [\App\Http\Controllers\Api\WriteController::class, 'storeBooking'])->middleware('throttle:20,1');
    Route::patch('/bookings/{id}/status', [\App\Http\Controllers\Api\WriteController::class, 'updateBookingStatus']);
    Route::delete('/bookings/{id}',       [\App\Http\Controllers\Api\WriteController::class, 'cancelBooking']);

    // Available slots
    Route::get('/available-slots', [\App\Http\Controllers\BookingController::class, 'availableSlots']);

    // Orders
    Route::get('/orders',               [\App\Http\Controllers\Api\DataController::class,  'orders']);
    Route::get('/orders/{id}',          [\App\Http\Controllers\Api\DataController::class,  'order']);
    Route::post('/orders',              [\App\Http\Controllers\Api\WriteController::class, 'storeOrder'])->middleware('throttle:20,1');
    Route::patch('/orders/{id}/status', [\App\Http\Controllers\Api\WriteController::class, 'updateOrderStatus']);

    // Clients
    Route::get('/clients',       [\App\Http\Controllers\Api\DataController::class,  'clients']);
    Route::get('/clients/{id}',  [\App\Http\Controllers\Api\DataController::class,  'client']);
    Route::post('/clients',      [\App\Http\Controllers\Api\WriteController::class, 'storeClient'])->middleware('throttle:20,1');

    // Products (все типы) + Services (только type=service, обратная совместимость)
    Route::get('/products',      [\App\Http\Controllers\Api\DataController::class,  'products']);
    Route::get('/products/{id}', [\App\Http\Controllers\Api\DataController::class,  'product']);
    Route::get('/services',      [\App\Http\Controllers\Api\DataController::class,  'services']);

    // Masters
    Route::get('/masters',         [\App\Http\Controllers\Api\DataController::class,  'masters']);
    Route::get('/masters/{id}',    [\App\Http\Controllers\Api\DataController::class,  'master']);
    Route::post('/masters',        [\App\Http\Controllers\Api\WriteController::class, 'storeMaster'])->middleware('throttle:20,1');
    Route::patch('/masters/{id}',  [\App\Http\Controllers\Api\WriteController::class, 'updateMaster']);
    Route::delete('/masters/{id}', [\App\Http\Controllers\Api\WriteController::class, 'deleteMaster']);

    // Categories
    Route::get('/categories', [\App\Http\Controllers\Api\DataController::class, 'categories']);
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
