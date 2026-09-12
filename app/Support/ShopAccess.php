<?php

namespace App\Support;

use App\Models\Shop;
use App\Models\ShopStaff;
use App\Models\User;

/**
 * Единственное место, где решается «в какой магазин пришёл запрос этого
 * пользователя и с какой ролью». Раньше эта логика была продублирована в
 * SetShopFromAuth (middleware) и AuthController::resolveShopAndRole — теперь
 * оба используют этот класс.
 *
 * Нужен из-за владельцев сети (users.is_chain_owner): у обычного шопера ровно
 * один магазин и hasOne User::shop() достаточно, но у владельца сети магазинов
 * несколько, и «какой из них правильный» уже не решить одной связью.
 *
 * @phpstan-type ShopContext array{shop: Shop, role: string, staff: ?ShopStaff}
 */
final class ShopAccess
{
    /**
     * Контекст по явно указанному магазину (владелец сети выбрал точку сам).
     * Владение проверяется на каждый вызов — доверять клиентскому shop_id
     * нельзя. Веток на is_superadmin намеренно нет: владелец платформы не
     * должен получать доступ к чужому магазину этим путём.
     *
     * @return ShopContext|null
     */
    public static function forShop(User $user, string $shopId): ?array
    {
        $owned = Shop::where('id', $shopId)->where('user_id', $user->id)->first();
        if ($owned) {
            return ['shop' => $owned, 'role' => 'owner', 'staff' => null];
        }

        $staff = ShopStaff::where('shop_id', $shopId)
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->with('shop')
            ->first();

        if ($staff && $staff->shop) {
            return ['shop' => $staff->shop, 'role' => $staff->role, 'staff' => $staff];
        }

        return null;
    }

    /**
     * Контекст по умолчанию — когда запрос не указал магазин явно.
     *
     * @return ShopContext|null null означает «магазин нужно выбрать» (только
     *   для владельца сети с несколькими точками) либо «магазина вообще нет».
     */
    public static function defaultFor(User $user): ?array
    {
        $owned = $user->shops()->orderBy('created_at')->orderBy('id')->limit(2)->get();

        if ($owned->count() === 1) {
            return ['shop' => $owned->first(), 'role' => 'owner', 'staff' => null];
        }

        if ($owned->count() > 1) {
            if ($user->is_chain_owner) {
                return null;
            }

            // Флаг сняли, а магазинов больше одного — не оставлять человека
            // без доступа вообще, отдаём самый старый детерминированно.
            return ['shop' => $owned->first(), 'role' => 'owner', 'staff' => null];
        }

        $staff = ShopStaff::where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->with('shop')
            ->first();

        return ($staff && $staff->shop)
            ? ['shop' => $staff->shop, 'role' => $staff->role, 'staff' => $staff]
            : null;
    }
}
