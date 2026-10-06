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

        // Сборщик, привязанный к администратору, наследует его ограничение по
        // категориям — тем самым весь существующий код, фильтрующий по
        // staff_category_ids (CategoryAccess, OrderController, NavCounts),
        // начинает резать видимость сборщику без отдельных правок. Сборщик
        // без admin_id («подчиняется владельцу») или с неограниченным
        // администратором — видит всё, как раньше.
        $categoryIds = $staff?->category_ids;
        if ($staff && $staff->role === 'collector' && $staff->admin_id) {
            $categoryIds = $staff->admin?->category_ids;
        }

        $request->attributes->set('shop', $shop);
        $request->attributes->set('staff_role', $ctx['role']);
        $request->attributes->set('staff_id', $staff?->id);
        $request->attributes->set('staff_master_id', $staff?->master_id);
        $request->attributes->set('staff_category_ids', $categoryIds);

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
