<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
