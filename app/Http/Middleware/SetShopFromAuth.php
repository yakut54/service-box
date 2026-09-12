<?php

namespace App\Http\Middleware;

use App\Services\TenantService;
use App\Support\ShopAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SetShopFromAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Не авторизован'], 401);
        }

        $actingShopId = $request->header('X-Acting-Shop-Id');

        if ($actingShopId !== null && $actingShopId !== '') {
            if (!Str::isUuid($actingShopId)) {
                return response()->json([
                    'message' => 'Некорректный магазин',
                    'code'    => 'acting_shop_invalid',
                ], 400);
            }

            // Владение/членство проверяется на каждый запрос — заголовку
            // самому по себе не доверяем. Владелец платформы (is_superadmin)
            // этим путём в чужой магазин не входит: ShopAccess::forShop не
            // делает для него исключений — так и задумано.
            $ctx = ShopAccess::forShop($user, $actingShopId);

            if (!$ctx) {
                return response()->json([
                    'message' => 'Нет доступа к этому магазину',
                    'code'    => 'acting_shop_forbidden',
                ], 403);
            }
        } else {
            $ctx = ShopAccess::defaultFor($user);

            if (!$ctx) {
                // Владелец сети без выбранной точки — не «не авторизован»,
                // а «выбери магазин» (фронт по этому коду уводит в панель
                // сети). Все остальные без магазина — как и раньше, 401.
                if ($user->is_chain_owner) {
                    return response()->json([
                        'message' => 'Выберите магазин',
                        'code'    => 'shop_not_selected',
                    ], 409);
                }

                return response()->json(['message' => 'Не авторизован'], 401);
            }
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
