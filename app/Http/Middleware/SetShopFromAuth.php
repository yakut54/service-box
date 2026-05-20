<?php

namespace App\Http\Middleware;

use App\Models\ShopStaff;
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
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Owner path: user owns a shop directly
        $shop = $user->shop;

        if ($shop) {
            $request->attributes->set('shop', $shop);
            $request->attributes->set('staff_role', 'owner');
            TenantService::setContext($shop);
            $response = $next($request);
            TenantService::resetContext();
            return $response;
        }

        // Staff path: user is a staff member of a shop
        $staffRecord = ShopStaff::where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->with('shop')
            ->first();

        // Return 401 (not 404) so the frontend unauthorized handler triggers logout
        if (!$staffRecord || !$staffRecord->shop) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $shop = $staffRecord->shop;
        $request->attributes->set('shop', $shop);
        $request->attributes->set('staff_role', $staffRecord->role);
        TenantService::setContext($shop);
        $response = $next($request);
        TenantService::resetContext();
        return $response;
    }
}
