<?php

namespace App\Services;

use App\Events\OrderAssignmentRequested;
use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopStaff;
use App\Models\User;

/**
 * Единая точка "выбрали кандидата на заказ" — для бота (Telegram/MAX) и
 * веб-админки (кнопка «В работу» у владельца/админа). Раньше выбор сразу
 * значил claimBy(), без участия выбранного человека — живой разбор
 * 2026-10-19: офлайн-человека назначали молча, сам он не знал. Теперь:
 *
 * - выбрал САМОГО СЕБЯ ($candidateId === $initiatorUserId) — спрашивать
 *   подтверждения не у кого, claimBy() сразу, как и было;
 * - выбрал КОГО-ТО ДРУГОГО, кто сейчас в сети — это предложение, не
 *   факт: claimBy() ждёт, пока кандидат сам примет (accept()) или
 *   откажется (decline()) в своём личном кабинете;
 * - выбрал того, кто не в сети — отказ сразу, без создания запроса.
 *
 * $initiatorUserId — кто выбирает. В боте это всегда владелец привязанного
 * чата ($shop->user_id) — бот физически не знает, кто держит телефон
 * магазина, только чей аккаунт к нему привязан. В вебе — $request->user()->id,
 * известен точно.
 */
class OrderAssignmentFlow
{
    /**
     * @return array{ok: bool, pending?: bool, actor_name?: string, message?: string, collector_name?: string}
     */
    public static function request(Order $order, Shop $shop, string $initiatorUserId, string $candidateId): array
    {
        $resolved = OrderCollectorCandidates::resolve($shop, $candidateId);
        if (!$resolved) {
            return ['ok' => false, 'message' => 'Сотрудник не найден'];
        }

        if ($candidateId === $initiatorUserId) {
            $result = $order->claimBy($candidateId, $resolved['name']);

            if ($result['ok']) {
                OrderActivityLog::record($order, $candidateId, $resolved['name'], $resolved['role'], 'claimed');
            }

            return array_merge($result, ['pending' => false, 'actor_name' => $resolved['name']]);
        }

        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            return ['ok' => false, 'message' => 'Этот заказ уже закрыт'];
        }

        if (!User::isOnline($candidateId)) {
            return ['ok' => false, 'message' => 'Нельзя выбрать сотрудника, который сейчас не в сети'];
        }

        // Живой разбор 2026-10-19: список кандидатов (OrderCollectorCandidates::
        // listFor()) уже прячет сотрудников вне их категории — без этой же
        // проверки здесь это было бы только украшением UI: прямой вызов API с
        // candidate_id в обход списка всё равно прошёл бы. Владелец — всегда
        // без ограничений, этот блок его не касается.
        if ($candidateId !== $shop->user_id) {
            $candidateStaff = ShopStaff::where('shop_id', $shop->id)->where('user_id', $candidateId)->first();
            if ($candidateStaff && !OrderCollectorCandidates::inCategoryScope($candidateStaff, OrderCollectorCandidates::categoryIdsFor($order))) {
                return ['ok' => false, 'message' => 'Этот сотрудник не занимается товарами этого заказа'];
            }
        }

        $initiatorResolved = OrderCollectorCandidates::resolve($shop, $initiatorUserId);
        $initiatorName = $initiatorResolved['name'] ?? 'Система';

        $order->update([
            'pending_collector_id' => $candidateId,
            'pending_collector_name' => $resolved['name'],
            'pending_assignment_requested_at' => now(),
            'pending_initiator_id' => $initiatorUserId,
            'pending_initiator_name' => $initiatorName,
        ]);

        // Актёр записи — инициатор (это ЕГО действие "предложил заказ"),
        // имя кандидата — в note. Раньше актёром писался кандидат с пустым
        // note, из-за чего "История заказа" показывала запись без названия
        // действия (ACTION_LABELS на фронте её не знал вообще).
        OrderActivityLog::record($order, $initiatorUserId, $initiatorName, $initiatorResolved['role'] ?? 'owner', 'assignment_requested', null, null, $resolved['name']);
        OrderAssignmentRequested::dispatch($shop->id, $order->id, $candidateId, $resolved['name'], $initiatorName);

        return ['ok' => true, 'pending' => true, 'actor_name' => $resolved['name']];
    }

    /**
     * Кандидат подтверждает ("Беру") — только теперь реально claimBy().
     * Имя/роль резолвятся заново через $shop, не берутся из уже
     * денормализованного pending_collector_name — тот же принцип "не
     * доверять без проверки", что у resolve() везде в этом флоу.
     *
     * @return array{ok: bool, message?: string, collector_name?: string, actor_name?: string}
     */
    public static function accept(Order $order, Shop $shop, string $userId): array
    {
        if (!$order->pending_collector_id || $order->pending_collector_id !== $userId) {
            return ['ok' => false, 'message' => 'Этот запрос не для вас'];
        }

        $resolved = OrderCollectorCandidates::resolve($shop, $userId);
        if (!$resolved) {
            return ['ok' => false, 'message' => 'Сотрудник не найден'];
        }

        $result = $order->claimBy($userId, $resolved['name']);

        $order->update([
            'pending_collector_id' => null,
            'pending_collector_name' => null,
            'pending_assignment_requested_at' => null,
            'pending_initiator_id' => null,
            'pending_initiator_name' => null,
        ]);

        if ($result['ok']) {
            OrderActivityLog::record($order, $userId, $resolved['name'], $resolved['role'], 'claimed');
        }

        return array_merge($result, ['actor_name' => $resolved['name']]);
    }

    /**
     * Кандидат отказывается ("Не беру") — claim так и не произошёл,
     * status/collector_id не трогаются, просто снимаем pending-запрос.
     * initiator_id в ответе — тот, кто предлагал: единственный способ
     * адресовать ему попап "вам отказали" (живой разбор 2026-10-19: отказ
     * раньше менял карточку тихо, у инициатора не было уведомления вообще).
     *
     * @return array{ok: bool, message?: string, decliner_name?: string, initiator_id?: string}
     */
    public static function decline(Order $order, Shop $shop, string $userId): array
    {
        if (!$order->pending_collector_id || $order->pending_collector_id !== $userId) {
            return ['ok' => false, 'message' => 'Этот запрос не для вас'];
        }

        $resolved = OrderCollectorCandidates::resolve($shop, $userId);
        $declinerName = $resolved['name'] ?? $order->pending_collector_name;
        $initiatorId = $order->pending_initiator_id;

        $order->update([
            'pending_collector_id' => null,
            'pending_collector_name' => null,
            'pending_assignment_requested_at' => null,
            'pending_initiator_id' => null,
            'pending_initiator_name' => null,
        ]);

        OrderActivityLog::record($order, $userId, $declinerName, $resolved['role'] ?? 'collector', 'assignment_declined');

        return ['ok' => true, 'decliner_name' => $declinerName, 'initiator_id' => $initiatorId];
    }

    /**
     * Инициатор (или владелец/админ) снимает зависший запрос — кандидат не
     * ответил и не обязан отвечать прямо сейчас (живой разбор 2026-10-19:
     * пользователь явно отказался от авто-снятия по таймеру — "никакой
     * автоматики, решает человек"). Не наказываем кандидата записью вроде
     * "отклонил" — это не его решение, поэтому отдельное действие.
     *
     * @return array{ok: bool, message?: string, candidate_name?: string}
     */
    public static function cancel(Order $order, Shop $shop, string $userId): array
    {
        if (!$order->pending_collector_id) {
            return ['ok' => false, 'message' => 'Запрос уже не висит'];
        }

        $resolved = OrderCollectorCandidates::resolve($shop, $userId);
        if (!$resolved) {
            return ['ok' => false, 'message' => 'Сотрудник не найден'];
        }

        // Снять может инициатор или владелец/админ магазина — не обязательно
        // тот же человек, кто создавал запрос (например, владелец видит,
        // что его админ предложил заказ и подвис, и снимает сам).
        $isInitiator = $order->pending_initiator_id === $userId;
        $isManager = in_array($resolved['role'], ['owner', 'admin'], true);
        if (!$isInitiator && !$isManager) {
            return ['ok' => false, 'message' => 'Нельзя снять чужой запрос'];
        }

        $candidateName = $order->pending_collector_name;

        $order->update([
            'pending_collector_id' => null,
            'pending_collector_name' => null,
            'pending_assignment_requested_at' => null,
            'pending_initiator_id' => null,
            'pending_initiator_name' => null,
        ]);

        OrderActivityLog::record($order, $userId, $resolved['name'], $resolved['role'], 'assignment_cancelled', null, null, $candidateName);

        return ['ok' => true, 'candidate_name' => $candidateName];
    }
}
