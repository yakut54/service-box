<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Новое сообщение в любом треде чата — обновляет бейдж «Чат» в сайдбаре,
 * пока у сотрудника не открыт конкретно этот тред (там своя, per-thread
 * подписка, см. ChatView.vue). Тот же общий канал магазина, что и
 * OrdersUpdated/ReviewsUpdated/StaffUpdated — доступен всем ролям, не
 * только owner/admin (в отличие от chat.thread.{apiKey}.{threadId}).
 */
class ChatUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $shopId) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("shop.{$this->shopId}");
    }

    public function broadcastAs(): string
    {
        return 'chat.updated';
    }
}
