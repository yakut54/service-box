<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Единственная реализация транспорта push. Позже рядом встанет
        // RuStore Universal Push / HMS — тогда здесь появится выбор по платформе.
        $this->app->bind(\App\Contracts\PushTransport::class, \App\Services\FirebaseService::class);
    }

    public function boot(): void
    {
        RateLimiter::for('telegram-webhook', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('yookassa-webhook', function (Request $request) {
            $shopId = $request->input('object.metadata.shop_id') ?? $request->ip();
            return Limit::perMinute(30)->by("yookassa:{$shopId}");
        });

        // Дефолтный ответ Laravel при превышении лимита — захардкоженный
        // английский текст "Too Many Attempts.", не переведённый через lang
        // файлы (баг найден 2026-08-23 — шоперу-владельцу показывало текст
        // на английском). Переопределяем на всех чатовых лимитерах ниже.
        $tooManyAttempts = fn (Request $request, array $headers) => response()->json(
            ['message' => 'Слишком много запросов, подождите немного'],
            429,
            $headers
        );

        // Чат покупателя: ключ — токен сессии (X-Phone-Session), не IP.
        // Покупатель авторизован кастомным заголовком, а не через Sanctum-guard,
        // поэтому $request->user() пуст и голый throttle:N,1 откатился бы на IP —
        // на carrier-grade NAT мобильных операторов это значило бы, что один
        // активный чат исчерпывает лимит на всех соседей по тому же оператору
        // (см. PLAN-CHAT.md §3.3). IP — только аварийный fallback, если заголовка
        // почему-то нет вообще (такой запрос всё равно отклонит
        // VerifyPhoneSession раньше, чем дойдёт до контроллера).
        RateLimiter::for('chat-read', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(60)->by($request->header('X-Phone-Session') ?? $request->ip())->response($tooManyAttempts);
        });
        RateLimiter::for('chat-write', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(20)->by($request->header('X-Phone-Session') ?? $request->ip())->response($tooManyAttempts);
        });
        RateLimiter::for('chat-image', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(10)->by($request->header('X-Phone-Session') ?? $request->ip())->response($tooManyAttempts);
        });

        // Чат в админке — именованные лимитеры вместо голого throttle:N,1.
        // БАГ (найден 2026-08-23 живьём): голый throttle:N,1 для
        // авторизованного пользователя строит ключ ИСКЛЮЧИТЕЛЬНО из
        // sha1(user_id), без учёта роута — то есть все 8 роутов чата
        // админки (index/messages/poll/read/block/image, каждый со своим
        // числом попыток) делили ОДИН общий счётчик на владельца магазина.
        // Экран диалога опрашивает messages каждые 4с + список тредов
        // каждые 10с + бейдж каждые 15с — это легко больше 20 запросов в
        // минуту само по себе, и отправка сообщения (лимит 20) утыкалась в
        // уже почти исчерпанный чужими GET'ами счётчик. Именованные
        // лимитеры с разными именами гарантированно не пересекаются.
        RateLimiter::for('chat-admin-read', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(180)->by($request->user()?->id ?? $request->ip())->response($tooManyAttempts);
        });
        RateLimiter::for('chat-admin-write', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(30)->by($request->user()?->id ?? $request->ip())->response($tooManyAttempts);
        });
        RateLimiter::for('chat-admin-moderate', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(30)->by($request->user()?->id ?? $request->ip())->response($tooManyAttempts);
        });
        RateLimiter::for('chat-admin-image', function (Request $request) use ($tooManyAttempts) {
            return Limit::perMinute(10)->by($request->user()?->id ?? $request->ip())->response($tooManyAttempts);
        });
    }
}
