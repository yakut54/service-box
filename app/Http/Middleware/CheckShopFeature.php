<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Гейт entitlements для /widget/* — см. Shop::hasFeature() и PLAN.md → МФ4.
 * Работает только за `tenant` middleware (нужен $request->get('_shop')).
 */
class CheckShopFeature
{
    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        $shop = $request->get('_shop');

        if (!$shop || !$shop->hasFeature($featureKey)) {
            return response()->json([
                'message' => 'Эта функция недоступна для данного магазина',
            ], 403);
        }

        return $next($request);
    }
}
