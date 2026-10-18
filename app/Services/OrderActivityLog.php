<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderActivity;

/**
 * Журнал действий с заказом — кто взял, кто отпустил, кто сменил статус, кто
 * отметил проблему. В отличие от StockJournal (только owner/admin), сюда
 * пишут все роли с доступом к заказу, включая сборщика — это его рабочие
 * действия, не ограничение видимости.
 *
 * Принимает актёра явными полями, а не Request (2026-10-18) — раньше брал
 * его из $request->user()/staff_role, что работало только для залогиненного
 * в админку сотрудника. Теперь это же действие может прийти из бота
 * (Telegram/MAX — выбор исполнителя в списке), где никакого HTTP Request с
 * Sanctum-сессией нет вовсе, но актёр всё равно точно известен (выбранный
 * shop_staff/владелец) — писать историю для него так же важно, как и для
 * клика в самой админке.
 */
class OrderActivityLog
{
    public static function record(
        Order $order,
        string $actorUserId,
        string $actorName,
        ?string $actorRole,
        string $action,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $note = null,
    ): void {
        OrderActivity::create([
            'order_id'      => $order->id,
            'action'        => $action,
            'from_status'   => $fromStatus,
            'to_status'     => $toStatus,
            'note'          => $note,
            'actor_user_id' => $actorUserId,
            'actor_name'    => $actorName,
            'actor_role'    => $actorRole,
        ]);
    }
}
