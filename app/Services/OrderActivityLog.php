<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderActivity;
use Illuminate\Http\Request;

/**
 * Журнал действий с заказом — кто взял, кто отпустил, кто сменил статус, кто
 * отметил проблему. В отличие от StockJournal (только owner/admin), сюда
 * пишут все роли с доступом к заказу, включая сборщика — это его рабочие
 * действия, не ограничение видимости.
 */
class OrderActivityLog
{
    public static function record(
        Order $order,
        Request $request,
        string $action,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $note = null,
    ): void {
        $user = $request->user();

        OrderActivity::create([
            'order_id'      => $order->id,
            'action'        => $action,
            'from_status'   => $fromStatus,
            'to_status'     => $toStatus,
            'note'          => $note,
            'actor_user_id' => $user->id,
            'actor_name'    => $user->name,
            'actor_role'    => $request->attributes->get('staff_role'),
        ]);
    }
}
