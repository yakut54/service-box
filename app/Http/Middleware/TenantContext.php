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
                'error' => 'Shop ID required',
                'message' => 'Provide shop_id in X-Shop-ID header or query parameter',
            ], 400);
        }

        try {
            $shop = TenantService::setContextByApiKey($shopId);
            $request->merge(['_shop' => $shop]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Invalid shop ID',
                'message' => $e->getMessage(),
            ], 401);
        }

        $response = $next($request);

        TenantService::resetContext();

        return $response;
    }
}
