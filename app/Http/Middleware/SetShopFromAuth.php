<?php

namespace App\Http\Middleware;

use App\Services\TenantService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetShopFromAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $shop = $user->shop;

        if (!$shop) {
            return response()->json([
                'message' => 'Shop not found for this user',
            ], 404);
        }

        TenantService::setContext($shop);
        $request->attributes->set('shop', $shop);

        return $next($request);
    }
}
