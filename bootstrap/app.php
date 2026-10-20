<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        // не реальный IP клиента. Раньше комментарий тут утверждал «порт
        // app-контейнера наружу не пробрасывается — доверять всем прокси
        // безопасно» — верно для app, но НЕ для web (docker-контейнер nginx),
        // чей порт 8000 был опубликован на 0.0.0.0 (см. docker-compose.prod.yml)
        // и реально принимал запросы напрямую из интернета, в обход host
        // nginx. web говорит с PHP-FPM через fastcgi_pass, который не трогает
        // X-Forwarded-For (в отличие от proxy_pass с $proxy_add_x_forwarded_for
        // у host nginx) — подключившись на 8000 напрямую, любой мог
        // подделать $request->ip() чем угодно. Подтверждено живым тестом
        // 2026-09-15: поддельный вебхук ЮKassa (VerifyYooKassaWebhook) реально
        // проходил IP-проверку через этот путь. Теперь web/reverb публикуют
        // порты только на 127.0.0.1 — утверждение выше наконец верно и для
        // них, доверять всем прокси снова безопасно.
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
        // Живой разбор 2026-10-20 — провал route-model-binding/findOrFail()
        // (неверный/удалённый id в URL) долетал до JSON-ответа сырым текстом
        // вида "No query results for model [App\Models\Order] <uuid>" даже с
        // APP_DEBUG=false. Ловим не сам ModelNotFoundException — Laravel уже
        // успевает завернуть его в NotFoundHttpException до того, как
        // кастомные render()-колбэки вообще видят исключение (prepareException()
        // отрабатывает раньше) — а именно эту обёртку, отличая её от
        // bare abort(404) по наличию "предыдущего" исключения: abort(404)
        // создаёт NotFoundHttpException с нуля, без getPrevious().
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['message' => 'Запись не найдена'], 404);
            }
        });

        // Та же дыра у ошибок БД — getMessage() у QueryException содержит
        // сырой SQL и значения параметров, это НЕЛЬЗЯ показывать пользователю
        // ни при каких обстоятельствах.
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->is('api/*')) {
                Log::error('Unhandled QueryException', ['message' => $e->getMessage()]);
                return response()->json(['message' => 'Ошибка сервера. Попробуйте позже.'], 500);
            }
        });

        // Переводим дефолтные английские тексты Laravel/Sanctum на русский —
        // остального (ValidationException, обычные 500 без debug) Laravel и
        // так отдаёт чисто, не трогаем.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Не авторизован'], 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Доступ запрещён'], 403);
            }
        });
    })->create();
