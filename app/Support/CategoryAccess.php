<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Http\Request;

/**
 * Ограничение доступа админа к категориям товаров — назначается владельцем
 * (см. StaffController::store/update, ShopStaff.category_ids). null везде
 * ниже означает «ограничения нет» (владелец и обычный, неограниченный админ) —
 * поведение как было до этой фичи, ничего не фильтруем.
 */
final class CategoryAccess
{
    /** Список id категорий ВЕРХНЕГО уровня, назначенных актору — null, если не ограничен. */
    public static function allowedTopLevelIds(Request $request): ?array
    {
        return $request->attributes->get('staff_category_ids');
    }

    /**
     * То же самое, но с добавлением id всех дочерних категорий — нужен для
     * фильтрации товаров: products.category_id может указывать на дочернюю
     * категорию, а не только на верхнеуровневую, которую выбрал владелец.
     */
    public static function expandedIds(Request $request): ?array
    {
        $topLevel = self::allowedTopLevelIds($request);

        if ($topLevel === null) {
            return null;
        }

        $childIds = Category::whereIn('parent_id', $topLevel)->pluck('id')->all();

        return array_values(array_unique([...$topLevel, ...$childIds]));
    }
}
