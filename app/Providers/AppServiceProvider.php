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
        //
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

        // Чат покупателя: ключ — токен сессии (X-Phone-Session), не IP.
        // Покупатель авторизован кастомным заголовком, а не через Sanctum-guard,
        // поэтому $request->user() пуст и голый throttle:N,1 откатился бы на IP —
        // на carrier-grade NAT мобильных операторов это значило бы, что один
        // активный чат исчерпывает лимит на всех соседей по тому же оператору
        // (см. PLAN-CHAT.md §3.3). IP — только аварийный fallback, если заголовка
        // почему-то нет вообще (такой запрос всё равно отклонит
        // VerifyPhoneSession раньше, чем дойдёт до контроллера).
        RateLimiter::for('chat-read', function (Request $request) {
            return Limit::perMinute(60)->by($request->header('X-Phone-Session') ?? $request->ip());
        });
        RateLimiter::for('chat-write', function (Request $request) {
            return Limit::perMinute(20)->by($request->header('X-Phone-Session') ?? $request->ip());
        });
        RateLimiter::for('chat-image', function (Request $request) {
            return Limit::perMinute(10)->by($request->header('X-Phone-Session') ?? $request->ip());
        });
    }
}
