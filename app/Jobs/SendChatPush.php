<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Shop;
use App\Services\FirebaseService;
use App\Services\TenantService;
use App\Support\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Push байеру о новом сообщении от магазина. Отправляется всегда, когда у
 * покупателя есть токен; решение «показывать или нет» — на клиенте: если
 * приложение на переднем плане и открыт именно этот чат, системная плашка
 * не появляется (onMessage), иначе — Android покажет её сам по каналу chat.
 * Серверный presence эфемерный (не хранится), поэтому здесь не проверяем.
 *
 * collapse_key = chat:{thread_id} — офлайн-устройство получит только последнее
 * сообщение вместо пачки.
 */
class SendChatPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly string $shopId,
        private readonly string $customerId,
        private readonly string $threadId,
        private readonly string $messageId,
        private readonly string $preview,
    ) {}

    /** Собрать из треда/сообщения в текущем тенантном контексте. */
    public static function dispatchFor(ChatThread $thread, ChatMessage $message): void
    {
        $shopId = TenantService::getCurrentShopId();
        if (!$shopId || !$thread->customer_id) {
            return;
        }

        $preview = ($message->body !== null && $message->body !== '')
            ? mb_substr($message->body, 0, 120)
            : '📷 Фото';

        self::dispatch($shopId, $thread->customer_id, $thread->id, $message->id, $preview);
    }

    public function handle(): void
    {
        $shop = Shop::find($this->shopId);
        if (!$shop) {
            return;
        }

        TenantService::inContext($shop, function () use ($shop) {
            $customer = Customer::find($this->customerId);
            if ($customer === null) {
                return;
            }

            FirebaseService::sendToCustomer($shop, $customer, new PushMessage(
                title: $shop->name !== '' ? $shop->name : 'Новое сообщение',
                body: $this->preview,
                data: [
                    'type'       => 'chat',
                    'thread_id'  => (string) $this->threadId,
                    'message_id' => (string) $this->messageId,
                ],
                channelId: 'chat',
                collapseKey: "chat:{$this->threadId}",
            ), 'chat', (string) $this->threadId);
        });
    }
}
