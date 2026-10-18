<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Кого-то назначили собирать заказ через бота (PLAN.md 2026-10-18: список
 * сотрудников в Telegram/MAX) — на общем телефоне магазина нажимает кто
 * угодно, не обязательно сам назначенный, и узнать об этом из бота он не
 * может. Шлём на тот же канал shop.{id}, на который и так подписаны все —
 * фронт сам решает, его ли это назначение (сравнивает collectorId с собой),
 * отдельный приватный канал на пользователя ради этого заводить незачем.
 */
class OrderAssignedNotice implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $shopId,
        public string $orderId,
        public string $collectorId,
        public string $collectorName,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("shop.{$this->shopId}");
    }

    public function broadcastAs(): string
    {
        return 'order.assigned';
    }

    public function broadcastWith(): array
    {
        return [
            'orderId'      => $this->orderId,
            'orderShortId' => substr($this->orderId, 0, 8),
            'collectorId'  => $this->collectorId,
            'collectorName' => $this->collectorName,
        ];
    }
}
