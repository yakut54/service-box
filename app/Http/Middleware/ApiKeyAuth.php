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
                'error'   => 'Не авторизован',
                'message' => 'Требуется API-ключ. Передайте его в заголовке X-API-Key.',
            ], 401);
        }

        try {
            $shop = TenantService::setContextByApiKey($apiKey);
            $request->merge(['_shop' => $shop]);
        } catch (\Exception) {
            return response()->json([
                'error'   => 'Не авторизован',
                'message' => 'Неверный API-ключ.',
            ], 401);
        }

        try {
            return $next($request);
        } finally {
            TenantService::resetContext();
        }
    }
}
