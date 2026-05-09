<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyMaxWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.max.webhook_secret');

        if ($expected && !hash_equals($expected, (string) $request->route('secret'))) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
