<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Список заказов сборщика/владельца должен обновляться сам — новый заказ,
 * смена статуса, кто-то взял заказ в работу или пометил проблему. Без
 * payload намеренно, как и StaffUpdated — фронт просто перезапрашивает
 * /api/admin/orders (список маленький, магазин один).
 */
class OrdersUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $shopId) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("shop.{$this->shopId}");
    }

    public function broadcastAs(): string
    {
        return 'orders.updated';
    }
}
