<?php

namespace Tests\Feature\Bots;

use App\Models\Order;
use App\Models\Shop;
use App\Services\TenantService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Живой разбор 2026-10-20 — уведомление владельцу о проблеме с заказом
 * ("Сообщение сборщика: ...") не называло, КТО именно сообщил, и не давало
 * телефон клиента — владельцу было не с кем связаться и непонятно, у кого
 * спросить подробности. См. TelegramService/MaxService::notifyOwnerOrderProblem.
 */
class OrderProblemNotificationTest extends TestCase
{
    private function orderForShop(Shop $shop): Order
    {
        TenantService::setContext($shop);

        return Order::create([
            'customer_name' => 'Артём',
            'customer_phone' => '+79137948487',
            'status' => 'processing',
        ])->refresh();
    }

    public function test_telegram_problem_notification_names_the_reporter_and_customer_phone(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $shop = $this->createTestShop(['telegram_chat_id' => random_int(100_000_000, 999_999_999), 'telegram_bot_connected' => true]);
        $order = $this->orderForShop($shop);
        $collector = $this->staffMember($shop, 'Юлия', 'admin');

        $this->actingAs($collector, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/problem", ['note' => 'Картошка гнилая'])
            ->assertOk();

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'sendMessage')) return false;
            $text = $request['text'] ?? '';
            return str_contains($text, 'Юлия')
                && str_contains($text, '+79137948487')
                && str_contains($text, 'Картошка гнилая');
        });
    }

    public function test_max_problem_notification_names_the_reporter_and_customer_phone(): void
    {
        config(['services.max.webhook_secret' => 'test-secret']);
        config(['services.max.bot_token' => 'test-token']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $shop = $this->createTestShop(['max_chat_id' => random_int(100_000_000, 999_999_999), 'max_bot_connected' => true]);
        $order = $this->orderForShop($shop);
        $collector = $this->staffMember($shop, 'Юлия', 'admin');

        $this->actingAs($collector, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/problem", ['note' => 'Картошка гнилая'])
            ->assertOk();

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'messages')) return false;
            $text = $request['text'] ?? '';
            return str_contains($text, 'Юлия')
                && str_contains($text, '+79137948487')
                && str_contains($text, 'Картошка гнилая');
        });
    }
}
