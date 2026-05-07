<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\TenantService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckWidgetSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            return $next($request);
        }

        $shopId = TenantService::getCurrentShopId();
        $shop   = $shopId ? Shop::find($shopId) : null;

        if ($shop && !$shop->hasActiveSubscription()) {
            return response()->json([
                'message' => 'Магазин приостановлен. Пожалуйста, свяжитесь с продавцом.',
            ], 403);
        }

        return $next($request);
    }
}
