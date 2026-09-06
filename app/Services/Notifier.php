<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Shop;
use App\Support\PushMessage;
use Illuminate\Support\Facades\Cache;

/**
 * Оркестратор уведомлений байеру. Одно событие — один канал (с фолбэком),
 * плюс кросс-канальный кап частоты.
 *
 * Каналы (по приоритету): push → Telegram → MAX. Email и in-app-инбокс — пока
 * не подключены (их для статусов заказа/чата ещё нет), добавятся позже в этот
 * же метод.
 *
 * Тиры:
 *   1 (транзакционный) — статус заказа, доплата, чат. Всегда, вне лимита. После
 *     такого сообщения tier 2/3 придерживаются на TIER1_QUIET_HOURS часов.
 *   2 (поведенческий)  — брошенная корзина, back-in-stock, win-back.
 *   3 (кампания)       — промо. Не уходит в день заказа («заказал сегодня»).
 * Tier 2/3 суммарно ограничены DAILY_CAP сообщениями в сутки по всем каналам.
 *
 * Счётчики — в Cache (драйвер file), ключи с TTL; отдельной таблицы не заводим.
 */
class Notifier
{
    public const TIER_TRANSACTIONAL = 1;
    public const TIER_BEHAVIORAL    = 2;
    public const TIER_CAMPAIGN      = 3;

    private const DAILY_CAP         = 3;
    private const TIER1_QUIET_HOURS = 4;

    /**
     * @param  string      $entityType  для push_failures
     * @param  string|null $fallbackText plain-текст для Telegram/MAX, если push не дошёл
     * @return bool доставлено хотя бы одним каналом (или намеренно придержано → false)
     */
    public static function toCustomer(
        Shop $shop,
        Customer $customer,
        int $tier,
        PushMessage $push,
        string $entityType,
        ?string $entityId = null,
        ?string $fallbackText = null,
    ): bool {
        if ($tier !== self::TIER_TRANSACTIONAL && !self::allowed($shop, $customer, $tier)) {
            return false;
        }

        $delivered = FirebaseService::sendToCustomer($shop, $customer, $push, $entityType, $entityId);

        if (!$delivered && $fallbackText !== null) {
            $delivered = self::viaMessenger($customer, $fallbackText);
        }

        self::recordSend($shop->id, $customer->id, $tier);

        return $delivered;
    }

    /** Отметить «заказал сегодня» — звать при создании заказа (гейт для кампаний). */
    public static function markOrdered(string $shopId, string $customerId): void
    {
        Cache::put("notif:ordered:{$shopId}:{$customerId}", true, now()->addDay());
    }

    private static function viaMessenger(Customer $customer, string $text): bool
    {
        if ($customer->telegram_chat_id
            && TelegramService::sendToCustomer((int) $customer->telegram_chat_id, $text)) {
            return true;
        }

        if ($customer->max_user_id
            && MaxService::sendRaw((int) $customer->max_user_id, $text) !== null) {
            return true;
        }

        return false;
    }

    private static function allowed(Shop $shop, Customer $customer, int $tier): bool
    {
        $shopId = $shop->id;
        $customerId = $customer->id;

        // Байер отключил эту категорию в профиле (tier 2 → поведенческие,
        // tier 3 → кампании). Транзакционные сюда не доходят.
        $category = $tier === self::TIER_CAMPAIGN ? 'campaign' : 'behavioral';
        if (!$customer->wantsNotificationCategory($category)) {
            return false;
        }

        // Недавно было транзакционное — не мешаем поверх него поведенческим/промо.
        if (Cache::has("notif:t1:{$shopId}:{$customerId}")) {
            return false;
        }

        // Кампании — не в день заказа.
        if ($tier === self::TIER_CAMPAIGN && Cache::has("notif:ordered:{$shopId}:{$customerId}")) {
            return false;
        }

        return self::capCount($shopId, $customerId) < self::DAILY_CAP;
    }

    private static function recordSend(string $shopId, string $customerId, int $tier): void
    {
        if ($tier === self::TIER_TRANSACTIONAL) {
            Cache::put(
                "notif:t1:{$shopId}:{$customerId}",
                now()->timestamp,
                now()->addHours(self::TIER1_QUIET_HOURS),
            );
            return;
        }

        Cache::put(self::capKey($shopId, $customerId), self::capCount($shopId, $customerId) + 1, now()->addDay());
    }

    private static function capKey(string $shopId, string $customerId): string
    {
        return "notif:cap:{$shopId}:{$customerId}:" . now()->format('Ymd');
    }

    private static function capCount(string $shopId, string $customerId): int
    {
        return (int) Cache::get(self::capKey($shopId, $customerId), 0);
    }
}
