<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Кандидат отказался от предложенного заказа — адресовано тому, кто
 * предлагал (initiatorId). До этого события отказ в веб-админке был
 * виден только как тихая смена текста в карточке заказа (строка "Сборка"
 * возвращалась к "не взят в работу"), хотя в боте тот же отказ уже
 * показывался явным сообщением с кнопками "выберите другого" (живой
 * разбор 2026-10-19).
 *
 * Канал тот же shop.{id}, на который и так подписаны все — фильтр "это
 * мне" делается на фронте по initiatorId, отдельный приватный канал на
 * пользователя заводить незачем (тот же приём, что у
 * OrderAssignmentRequested).
 */
class OrderAssignmentDeclined implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $shopId,
        public string $orderId,
        public string $initiatorId,
        public string $declinerName,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("shop.{$this->shopId}");
    }

    public function broadcastAs(): string
    {
        return 'order.assignment_declined';
    }

    public function broadcastWith(): array
    {
        return [
            'orderId'      => $this->orderId,
            'orderShortId' => substr($this->orderId, 0, 8),
            'initiatorId'  => $this->initiatorId,
            'declinerName' => $this->declinerName,
        ];
    }
}
