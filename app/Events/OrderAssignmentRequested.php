<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Кого-то предложили назначить собирать заказ (бот или веб-админка,
 * см. OrderAssignmentFlow::request()) — он ещё НЕ исполнитель, только
 * кандидат, ждущий подтверждения. Раньше (2026-10-18) это событие значило
 * "вас уже назначили" и claimBy() срабатывал мгновенно — живой разбор
 * 2026-10-19 показал дыру: офлайн-человека можно было "назначить" молча,
 * он никогда бы не узнал. Теперь claimBy() ждёт ответа самого кандидата
 * (accept/decline), это событие — только приглашение.
 *
 * Шлём на тот же канал shop.{id}, на который и так подписаны все — фронт
 * сам решает, его ли это приглашение (сравнивает candidateId с собой),
 * отдельный приватный канал на пользователя ради этого заводить незачем.
 */
class OrderAssignmentRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $shopId,
        public string $orderId,
        public string $candidateId,
        public string $candidateName,
        public string $initiatorName,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("shop.{$this->shopId}");
    }

    public function broadcastAs(): string
    {
        return 'order.assignment_requested';
    }

    /**
     * Сводка заказа (позиции/сумма/доставка) — чтобы кандидат не отвечал
     * вслепую, видя в попапе только номер заказа (живой разбор 2026-10-19:
     * "Юлия не знает, что берёт"). Собирается здесь же, одним местом, а не
     * копипастой в каждом контроллере, который дёргает request().
     */
    public function broadcastWith(): array
    {
        $order = Order::find($this->orderId);

        return [
            'orderId'        => $this->orderId,
            'orderShortId'   => substr($this->orderId, 0, 8),
            'candidateId'    => $this->candidateId,
            'candidateName'  => $this->candidateName,
            'initiatorName'  => $this->initiatorName,
            'itemsCount'     => $order?->items?->count(),
            'totalPrice'     => $order?->total_price,
            'deliveryMethod' => $order?->delivery_method,
        ];
    }
}
