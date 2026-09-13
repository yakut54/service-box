<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Сотрудник принял приглашение с другого устройства — владелец, у которого
 * открыта страница «Команда», должен увидеть статус «Ожидает» → «Активен»
 * без перезагрузки страницы (см. PLAN-CHAT.md §12 про тот же приём для чата).
 * Полезной нагрузки нет намеренно — фронт просто перезапрашивает список
 * сотрудников целиком (он маленький, дублировать сериализацию строки
 * StaffController::index() здесь ни к чему).
 */
class StaffUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $shopId) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("shop.{$this->shopId}");
    }

    public function broadcastAs(): string
    {
        return 'staff.updated';
    }
}
