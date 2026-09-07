<?php

namespace App\Jobs;

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
 * «Тихий» push байеру: сообщение от магазина удалили — убери его плашку из
 * шторки уведомлений, если она ещё висит. Без текста и звука (dataOnly),
 * приложение обрабатывает его само (foreground + фоновый обработчик) и гасит
 * уведомления с тегом chat-{thread_id}, который проставил SendChatPush.
 *
 * Идёт только через FCM (шторка — это Android), без фолбэка в Telegram/MAX:
 * там ничего гасить не нужно, а «пустое» сообщение туда слать бессмысленно.
 */
class SendChatMessageDeletedPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;
    public int $backoff = 30;

    public function __construct(
        private readonly string $shopId,
        private readonly string $customerId,
        private readonly string $threadId,
    ) {}

    /** Собрать из треда в текущем тенантном контексте. */
    public static function dispatchFor(ChatThread $thread): void
    {
        $shopId = TenantService::getCurrentShopId();
        if (!$shopId || !$thread->customer_id) {
            return;
        }

        self::dispatch($shopId, $thread->customer_id, $thread->id);
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

            FirebaseService::sendToCustomer(
                $shop,
                $customer,
                new PushMessage(
                    title: '',
                    body: '',
                    data: [
                        'type'      => 'chat_deleted',
                        'thread_id' => (string) $this->threadId,
                    ],
                    collapseKey: "chat:{$this->threadId}",
                    dataOnly: true,
                ),
                entityType: 'chat_deleted',
                entityId: (string) $this->threadId,
            );
        });
    }
}
