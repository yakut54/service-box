<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Один класс на все виды событий в чате (новое сообщение / правка / удаление /
 * массовая отметка прочитанным) — differentiated через `type` + `broadcastAs()`,
 * не четыре одинаковых класса. `QUEUE_CONNECTION=sync` в проекте и так
 * выполняет джобы синхронно, но ShouldBroadcastNow не зависит от этой
 * настройки вообще — событие уходит в Reverb сразу внутри того же запроса,
 * без очереди (см. PLAN-CHAT.md §12).
 */
class ChatMessageBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $shopApiKey,
        public string $threadId,
        public string $type,
        public array $payload,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("chat.thread.{$this->shopApiKey}.{$this->threadId}");
    }

    public function broadcastAs(): string
    {
        return $this->type;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
