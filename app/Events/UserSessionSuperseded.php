<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Владелец/сотрудник вошёл с нового устройства — старые токены того же
 * пользователя уже удалены (см. AuthController::login, «одна активная
 * сессия», решение принято ранее). Уже открытая вкладка на другом
 * устройстве узнаёт об этом через приватный канал именно пользователя
 * (не магазина — несколько сотрудников физически не делят один аккаунт,
 * но сам владелец может зайти с телефона и с компьютера одновременно).
 */
class UserSessionSuperseded implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public string $userId,
        public string $ip,
        public int $totalUsers,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("user.{$this->userId}");
    }

    public function broadcastAs(): string
    {
        return 'session.superseded';
    }

    public function broadcastWith(): array
    {
        return [
            'ip'          => $this->ip,
            'total_users' => $this->totalUsers,
            'at'          => now()->toIso8601String(),
        ];
    }
}
