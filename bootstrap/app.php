<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
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
            'verify.telegram' => \App\Http\Middleware\VerifyTelegramWebhook::class,
            'verify.max'      => \App\Http\Middleware\VerifyMaxWebhook::class,
            'verify.yookassa' => \App\Http\Middleware\VerifyYooKassaWebhook::class,
            'enforce.https' => \App\Http\Middleware\EnforceHttps::class,
            'rate.phone' => \App\Http\Middleware\RateLimitPhoneLookup::class,
            'verify.phone'   => \App\Http\Middleware\VerifyPhoneToken::class,
            'verify.phone.session' => \App\Http\Middleware\VerifyPhoneSession::class,
            'superadmin'     => \App\Http\Middleware\RequireSuperadmin::class,
            'force.json'     => \App\Http\Middleware\ForceJson::class,
            'api.auth'       => \App\Http\Middleware\ApiKeyAuth::class,
            'api.ratelimit'  => \App\Http\Middleware\ApiRateLimit::class,
            'api.cors'       => \App\Http\Middleware\ApiCors::class,
            'owner'          => \App\Http\Middleware\RequireOwner::class,
            'not.master'     => \App\Http\Middleware\RequireNotMaster::class,
            'feature'        => \App\Http\Middleware\CheckShopFeature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
