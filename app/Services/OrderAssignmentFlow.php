<?php

namespace App\Services;

use App\Events\OrderAssignmentRequested;
use App\Models\Order;
use App\Models\Shop;
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

            return array_merge($result, ['pending' => false, 'actor_name' => $resolved['name']]);
        }

        if (in_array($order->status, ['completed', 'cancelled'], true)) {
            return ['ok' => false, 'message' => 'Этот заказ уже закрыт'];
        }

        if (!User::isOnline($candidateId)) {
            return ['ok' => false, 'message' => 'Нельзя выбрать сотрудника, который сейчас не в сети'];
        }

        $order->update([
            'pending_collector_id' => $candidateId,
            'pending_collector_name' => $resolved['name'],
            'pending_assignment_requested_at' => now(),
        ]);

        OrderActivityLog::record($order, $candidateId, $resolved['name'], $resolved['role'], 'assignment_requested');
        OrderAssignmentRequested::dispatch($shop->id, $order->id, $candidateId, $resolved['name']);

        return ['ok' => true, 'pending' => true, 'actor_name' => $resolved['name']];
    }

    /**
     * Кандидат подтверждает ("Беру") — только теперь реально claimBy().
     *
     * @return array{ok: bool, message?: string, collector_name?: string}
     */
    public static function accept(Order $order, string $userId, string $userName): array
    {
        if (!$order->pending_collector_id || $order->pending_collector_id !== $userId) {
            return ['ok' => false, 'message' => 'Этот запрос не для вас'];
        }

        $result = $order->claimBy($userId, $userName);

        $order->update([
            'pending_collector_id' => null,
            'pending_collector_name' => null,
            'pending_assignment_requested_at' => null,
        ]);

        return $result;
    }

    /**
     * Кандидат отказывается ("Не беру") — claim так и не произошёл,
     * status/collector_id не трогаются, просто снимаем pending-запрос.
     *
     * @return array{ok: bool, message?: string, decliner_name?: string}
     */
    public static function decline(Order $order, string $userId): array
    {
        if (!$order->pending_collector_id || $order->pending_collector_id !== $userId) {
            return ['ok' => false, 'message' => 'Этот запрос не для вас'];
        }

        $declinerName = $order->pending_collector_name;

        $order->update([
            'pending_collector_id' => null,
            'pending_collector_name' => null,
            'pending_assignment_requested_at' => null,
        ]);

        return ['ok' => true, 'decliner_name' => $declinerName];
    }
}
