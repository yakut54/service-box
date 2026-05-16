<?php

namespace App\Http\Middleware;

use App\Services\TenantService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key');

        if (!$apiKey) {
            return response()->json([
                'error'   => 'Unauthorized',
                'message' => 'API key required. Pass it in the X-API-Key header.',
            ], 401);
        }

        try {
            $shop = TenantService::setContextByApiKey($apiKey);
            $request->merge(['_shop' => $shop]);
        } catch (\Exception) {
            return response()->json([
                'error'   => 'Unauthorized',
                'message' => 'Invalid API key.',
            ], 401);
        }

        try {
            return $next($request);
        } finally {
            TenantService::resetContext();
        }
    }
}
