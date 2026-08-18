<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local', 'testing')) {
            return $next($request);
        }

        if (!$request->secure()) {
            \Log::warning('HTTP request rejected (HTTPS required)', [
                'url' => $request->fullUrl(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'Доступ запрещён',
                'message' => 'Требуется HTTPS-соединение',
            ], 403);
        }

        return $next($request);
    }
}
