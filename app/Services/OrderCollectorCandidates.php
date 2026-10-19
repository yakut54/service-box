<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopStaff;
use App\Models\User;
use App\Support\CategoryAccess;
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
     * is_online — живой Sanctum-токен прямо сейчас (User::isOnline(),
     * тот же источник истины, что уже используется для "Активен" в
     * Команде и в карточке заказа). Нужен, чтобы пикер в боте/веб-админке
     * мог сразу показать ✅/❌ на каждой кнопке и отказать в выборе
     * офлайн-кандидата (живой разбор 2026-10-19).
     *
     * Живой разбор 2026-10-19: "если Юлия с Егоркой кроссовками не
     * занимаются, их не должно быть в списке делегирования заказа с
     * кроссовками" — $order обязателен, чтобы отфильтровать кандидатов,
     * чьё ограничение по категориям (ShopStaff::effectiveCategoryIds())
     * не пересекается ни с одним товаром в заказе. Владелец всегда без
     * ограничений (как и везде в CategoryAccess). Пустой/нераспознанный
     * набор категорий у самого заказа (нет товаров, товар без категории)
     * — НЕ режем никого: нет данных для фильтра, безопаснее показать всех,
     * чем молча остаться без единого кандидата.
     *
     * @return array<int, array{id: string, name: string, role: string, is_online: bool}>
     */
    public static function listFor(Shop $shop, Order $order): array
    {
        $orderCategoryIds = self::categoryIdsFor($order);

        $options = [[
            'id' => $shop->user_id,
            'name' => $shop->user->name,
            'role' => 'owner',
            'is_online' => User::isOnline($shop->user_id),
        ]];

        $staff = ShopStaff::where('shop_id', $shop->id)
            ->whereIn('role', ['admin', 'collector'])
            ->whereNotNull('accepted_at')
            ->with('user')
            ->get();

        foreach ($staff as $member) {
            if (!$member->user) {
                continue;
            }
            if (!self::inCategoryScope($member, $orderCategoryIds)) {
                continue;
            }
            $options[] = [
                'id' => $member->user_id,
                'name' => $member->user->name,
                'role' => $member->role,
                'is_online' => User::isOnline($member->user_id),
            ];
        }

        return $options;
    }

    /**
     * Та же проверка нужна и в listFor() (что показываем), и в
     * OrderAssignmentFlow::request() (что реально разрешаем — иначе
     * фильтр в списке был бы только украшением UI, прямой вызов API с
     * candidate_id в обход списка всё равно прошёл бы).
     */
    public static function inCategoryScope(ShopStaff $member, array $orderCategoryIds): bool
    {
        if (empty($orderCategoryIds)) {
            return true;
        }

        $allowed = CategoryAccess::expand($member->effectiveCategoryIds());
        if ($allowed === null) {
            return true;
        }

        return count(array_intersect($allowed, $orderCategoryIds)) > 0;
    }

    /**
     * Категории товаров заказа — вынесено отдельно, чтобы
     * OrderAssignmentFlow::request() не пересчитывал их заново по
     * $order->id вторым запросом, когда уже есть $order под рукой.
     *
     * @return array<int, string>
     */
    public static function categoryIdsFor(Order $order): array
    {
        return $order->items()
            ->with('product:id,category_id')
            ->get()
            ->pluck('product.category_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
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
