<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Один класс вместо отдельного Updated-события на каждую фичу (были
 * ReviewsUpdated, MailFailuresUpdated — дословно одно и то же: приватный
 * канал shop.{id}, свой фиксированный broadcastAs()). Имя события теперь
 * параметр, не отдельный класс.
 *
 * ::dispatch($shop->id, 'reviews.updated')
 */
class ShopChannelEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $shopId, public string $eventName) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("shop.{$this->shopId}");
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }
}
