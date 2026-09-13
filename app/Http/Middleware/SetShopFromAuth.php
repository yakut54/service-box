<?php

namespace App\Http\Middleware;

use App\Services\TenantService;
use App\Support\ShopAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetShopFromAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $ctx = ShopAccess::defaultFor($user);

        if (!$ctx) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $shop  = $ctx['shop'];
        $staff = $ctx['staff'];

        $request->attributes->set('shop', $shop);
        $request->attributes->set('staff_role', $ctx['role']);
        $request->attributes->set('staff_master_id', $staff?->master_id);

        // Обновляем last_login_at не чаще раза в 5 минут
        if ($staff && (is_null($staff->last_login_at) || $staff->last_login_at->diffInMinutes(now()) >= 5)) {
            $staff->updateQuietly(['last_login_at' => now()]);
        }

        TenantService::setContext($shop);
        $response = $next($request);
        TenantService::resetContext();
        return $response;
    }
}
