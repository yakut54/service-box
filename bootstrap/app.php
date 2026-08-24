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
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Host nginx проксирует всё на app-контейнер — без этого
        // $request->ip() везде в проекте (rate limiting по IP, uведомление о
        // входе с другого устройства, terms_accepted_ip и т.п.) возвращал бы
        // внутренний IP docker-сети (172.x, IP самого nginx-контейнера), а
        // не реальный IP клиента. Порт app-контейнера наружу не пробрасывается
        // (см. docker-compose.prod.yml) — доверять всем прокси безопасно,
        // достучаться до приложения в обход nginx физически нельзя.
        $middleware->trustProxies(at: '*');

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
            'collector.only' => \App\Http\Middleware\RequireCollectorAccess::class,
            'feature'        => \App\Http\Middleware\CheckShopFeature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
