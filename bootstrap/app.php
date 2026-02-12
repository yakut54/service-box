<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // CORS for frontend
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Middleware aliases
        $middleware->alias([
            'tenant' => \App\Http\Middleware\TenantContext::class,
            'auth.shop' => \App\Http\Middleware\SetShopFromAuth::class,
            'subscription' => \App\Http\Middleware\CheckSubscription::class,
            'verify.telegram' => \App\Http\Middleware\VerifyTelegramWebhook::class,
            'verify.yookassa' => \App\Http\Middleware\VerifyYooKassaWebhook::class,
            'enforce.https' => \App\Http\Middleware\EnforceHttps::class,
        ]);

        // Rate limiters
        RateLimiter::for('telegram-webhook', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('yookassa-webhook', function (Request $request) {
            $shopId = $request->input('object.metadata.shop_id') ?? $request->ip();
            return Limit::perMinute(30)->by("yookassa:{$shopId}");
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
