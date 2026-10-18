<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\ShopStaff;

/**
 * Кандидаты "кто берёт заказ" — общий список для Telegram- и MAX-бота
 * (PLAN.md 2026-10-18: статус "В работе" пишется только вместе с
 * исполнителем). Владелец + принятые admin/collector; роль master
 * сознательно не включаем — салоны/мастера вне фокуса этой фичи.
 */
class OrderCollectorCandidates
{
    /**
     * @return array<int, array{id: string, name: string}>
     */
    public static function listFor(Shop $shop): array
    {
        $options = [['id' => $shop->user_id, 'name' => $shop->user->name]];

        $staff = ShopStaff::where('shop_id', $shop->id)
            ->whereIn('role', ['admin', 'collector'])
            ->whereNotNull('accepted_at')
            ->with('user')
            ->get();

        foreach ($staff as $member) {
            if ($member->user) {
                $options[] = ['id' => $member->user_id, 'name' => $member->user->name];
            }
        }

        return $options;
    }

    /**
     * Резолвит имя по userId, но только если это реально владелец или
     * принятый admin/collector именно этого магазина — callback-данные
     * приходят от клиента, доверять userId без проверки нельзя (иначе
     * можно закрепить заказ за произвольным чужим id).
     */
    public static function resolveName(Shop $shop, string $actorUserId): ?string
    {
        if ($shop->user_id === $actorUserId) {
            return $shop->user->name;
        }

        $staff = ShopStaff::where('shop_id', $shop->id)
            ->where('user_id', $actorUserId)
            ->whereIn('role', ['admin', 'collector'])
            ->whereNotNull('accepted_at')
            ->with('user')
            ->first();

        return $staff?->user?->name;
    }
}
