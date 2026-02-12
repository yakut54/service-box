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
    }
}
