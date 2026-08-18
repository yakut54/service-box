<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ApiRateLimit
{
    private const MAX_ATTEMPTS = 60;
    private const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'ext-api:' . ($request->header('X-API-Key') ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return response()->json([
                'error'       => 'Слишком много запросов',
                'message'     => 'Превышен лимит запросов. Максимум 60 запросов в минуту.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return $next($request)->withHeaders([
            'X-RateLimit-Limit'     => self::MAX_ATTEMPTS,
            'X-RateLimit-Remaining' => RateLimiter::remaining($key, self::MAX_ATTEMPTS),
        ]);
    }
}
