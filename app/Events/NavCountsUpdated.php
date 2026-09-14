<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Товар/категория/скидка/клиент созданы или удалены — серые цифры «всего»
 * в сайдбаре (см. NavCountsController) должны обновиться сами, без
 * перезагрузки страницы (см. App\Events\ReviewsUpdated — тот же приём).
 */
class NavCountsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $shopId) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("shop.{$this->shopId}");
    }

    public function broadcastAs(): string
    {
        return 'nav_counts.updated';
    }
}
