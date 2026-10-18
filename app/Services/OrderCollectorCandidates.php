<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\ShopStaff;
use Illuminate\Support\Facades\Cache;

/**
 * Кандидаты "кто берёт заказ" — общий список для Telegram- и MAX-бота
 * (PLAN.md 2026-10-18: статус "В работе" пишется только вместе с
 * исполнителем). Владелец + принятые admin/collector; роль master
 * сознательно не включаем — салоны/мастера вне фокуса этой фичи.
 */
class OrderCollectorCandidates
{
    /**
     * У Telegram жёсткий лимит 64 байта на callback_data — "order:assign:" +
     * UUID заказа + UUID сотрудника уже ~86 байт, кнопка не влезает и
     * Telegram молча отвергает editMessageReplyMarkup (см. баг 2026-10-18:
     * кнопки оставались старыми, хотя answerCallback успевал отработать).
     * Поэтому кнопка шлёт короткий ИНДЕКС в списке, а не второй UUID — сам
     * список на момент показа кэшируется здесь же, под ключом по orderId.
     */
    private const CACHE_TTL_HOURS = 2;

    /**
     * @return array<int, array{id: string, name: string, role: string}>
     */
    public static function listFor(Shop $shop): array
    {
        $options = [['id' => $shop->user_id, 'name' => $shop->user->name, 'role' => 'owner']];

        $staff = ShopStaff::where('shop_id', $shop->id)
            ->whereIn('role', ['admin', 'collector'])
            ->whereNotNull('accepted_at')
            ->with('user')
            ->get();

        foreach ($staff as $member) {
            if ($member->user) {
                $options[] = ['id' => $member->user_id, 'name' => $member->user->name, 'role' => $member->role];
            }
        }

        return $options;
    }

    /**
     * Резолвит имя и роль по userId, но только если это реально владелец
     * или принятый admin/collector именно этого магазина — callback-данные
     * приходят от клиента, доверять userId без проверки нельзя (иначе
     * можно закрепить заказ за произвольным чужим id). Роль нужна не для
     * отображения — `order_activity.actor_role` в БД колонка NOT NULL, без
     * неё запись лога падает (баг 2026-10-18: claimBy() успевал отработать,
     * а OrderActivityLog::record() с null-ролью валил всё, что шло после —
     * снятие кнопок и подтверждение в чате).
     *
     * @return array{name: string, role: string}|null
     */
    public static function resolve(Shop $shop, string $actorUserId): ?array
    {
        if ($shop->user_id === $actorUserId) {
            return ['name' => $shop->user->name, 'role' => 'owner'];
        }

        $staff = ShopStaff::where('shop_id', $shop->id)
            ->where('user_id', $actorUserId)
            ->whereIn('role', ['admin', 'collector'])
            ->whereNotNull('accepted_at')
            ->with('user')
            ->first();

        if (!$staff || !$staff->user) {
            return null;
        }

        return ['name' => $staff->user->name, 'role' => $staff->role];
    }

    /**
     * Кэширует список кандидатов на момент показа пикера — вызывать перед
     * показом кнопок, до того как TelegramService/MaxService строят
     * callback_data с индексами из этого же списка.
     *
     * @param array<int, array{id: string, name: string}> $options
     */
    public static function cache(string $orderId, array $options): void
    {
        Cache::put("order_pick:{$orderId}", $options, now()->addHours(self::CACHE_TTL_HOURS));
    }

    /**
     * Достаёт кандидата по индексу из кэша, положенного cache(). null —
     * кэш истёк/заказ не показывался через пикер; вызывающий код должен
     * попросить нажать «Подтвердить» заново, а не падать молча.
     *
     * @return array{id: string, name: string}|null
     */
    public static function pickFromCache(string $orderId, int $index): ?array
    {
        $options = Cache::get("order_pick:{$orderId}");

        return $options[$index] ?? null;
    }
}
