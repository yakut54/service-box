<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitWebhook
{
    public function handle(Request $request, Closure $next, string $limiter = 'webhook'): Response
    {
        $key = $this->resolveRequestSignature($request, $limiter);

        if (RateLimiter::tooManyAttempts($key, $this->getMaxAttempts($limiter))) {
            return response()->json([
                'error' => 'Too many requests',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, $this->getDecaySeconds($limiter));

        $response = $next($request);

        return $response->withHeaders([
            'X-RateLimit-Limit' => $this->getMaxAttempts($limiter),
            'X-RateLimit-Remaining' => RateLimiter::remaining($key, $this->getMaxAttempts($limiter)),
        ]);
    }

    protected function resolveRequestSignature(Request $request, string $limiter): string
    {
        if ($limiter === 'telegram-webhook') {
            $chatId = $request->input('message.chat.id')
                     ?? $request->input('callback_query.message.chat.id')
                     ?? 'unknown';
            return "telegram-webhook:{$chatId}";
        }

        if ($limiter === 'yookassa-webhook') {
            $shopId = $request->input('object.metadata.shop_id') ?? 'unknown';
            return "yookassa-webhook:{$shopId}";
        }

        return "{$limiter}:{$request->ip()}";
    }

    protected function getMaxAttempts(string $limiter): int
    {
        return match($limiter) {
            'telegram-webhook' => 60,
            'yookassa-webhook' => 30,
            default => 60,
        };
    }

    protected function getDecaySeconds(string $limiter): int
    {
        return match($limiter) {
            'telegram-webhook' => 60,
            'yookassa-webhook' => 60,
            default => 60,
        };
    }
}
