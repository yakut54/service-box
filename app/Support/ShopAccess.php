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
 * @phpstan-type ShopContext array{shop: Shop, role: string, staff: ?ShopStaff}
 */
final class ShopAccess
{
    /**
     * Контекст по умолчанию — единственный магазин владельца, иначе принятая
     * запись в shop_staff.
     *
     * @return ShopContext|null null — магазина вообще нет.
     */
    public static function defaultFor(User $user): ?array
    {
        $owned = $user->shop;

        if ($owned) {
            return ['shop' => $owned, 'role' => 'owner', 'staff' => null];
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
