<?php

namespace App\Http\Middleware;

use App\Services\TenantService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $shopId = $request->header('X-Shop-ID')
                  ?? $request->query('shop_id')
                  ?? $request->input('shop_id');

        if (!$shopId) {
            return response()->json([
                'error' => 'Не указан ID магазина',
                'message' => 'Укажите shop_id в заголовке X-Shop-ID или в параметре запроса',
            ], 400);
        }

        try {
            $shop = TenantService::setContextByApiKey($shopId);
            $request->merge(['_shop' => $shop]);
        } catch (\Exception) {
            return response()->json([
                'error' => 'Неверный ID магазина',
                'message' => 'Магазин с таким ID не найден',
            ], 401);
        }

        try {
            $response = $next($request);
        } finally {
            TenantService::resetContext();
        }

        return $response;
    }
}
