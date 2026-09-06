<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Shop;
use App\Services\Notifier;
use App\Services\TenantService;
use App\Support\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Push байеру о смене статуса заказа. Только примитивы в конструкторе (без
 * SerializesModels) — воркер вне HTTP-запроса не имеет tenant search_path,
 * контекст восстанавливаем сами по shopId.
 *
 * collapse_key = order:{id}:status — если устройство было офлайн и статус
 * сменился несколько раз, доедет только последний.
 */
class SendOrderStatusPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    /** Статусы, о которых пишем байеру, и текст уведомления. Остальные молчат. */
    private const LABELS = [
        'paid'       => 'Оплата получена',
        'processing' => 'Заказ собирается',
        'completed'  => 'Заказ выполнен',
        'cancelled'  => 'Заказ отменён',
    ];

    public function __construct(
        private readonly string $shopId,
        private readonly string $customerId,
        private readonly string $orderId,
        private readonly string $status,
    ) {}

    /** Собрать джобу из заказа в текущем тенантном контексте. */
    public static function dispatchFor(Order $order, string $status): void
    {
        $shopId = TenantService::getCurrentShopId();
        if (!$shopId || !$order->customer_id || !isset(self::LABELS[$status])) {
            return;
        }

        self::dispatch($shopId, $order->customer_id, $order->id, $status);
    }

    public function handle(): void
    {
        $label = self::LABELS[$this->status] ?? null;
        if ($label === null) {
            return;
        }

        $shop = Shop::find($this->shopId);
        if (!$shop) {
            return;
        }

        TenantService::inContext($shop, function () use ($shop, $label) {
            $order = Order::with('customer')->find($this->orderId);
            $customer = $order?->customer;
            if ($order === null || $customer === null) {
                return;
            }

            $short = substr((string) $order->id, 0, 8);

            Notifier::toCustomer(
                $shop,
                $customer,
                Notifier::TIER_TRANSACTIONAL,
                new PushMessage(
                    title: "Заказ №{$short}",
                    body: $label,
                    data: [
                        'type'     => 'order_status',
                        'order_id' => (string) $order->id,
                        'status'   => $this->status,
                    ],
                    channelId: 'orders',
                    collapseKey: "order:{$order->id}:status",
                ),
                entityType: 'order_status',
                entityId: (string) $order->id,
                fallbackText: "Заказ №{$short}: {$label}",
            );
        });
    }
}
