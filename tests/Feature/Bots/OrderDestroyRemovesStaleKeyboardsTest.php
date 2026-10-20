<?php

namespace Tests\Feature\Bots;

use App\Models\Order;
use App\Models\Shop;
use App\Models\TelegramMessage;
use App\Services\TenantService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Живой разбор 2026-10-20 — удаление заказа (DELETE /api/admin/orders/{id})
 * никогда не снимало клавиатуру со старого сообщения бота: клик по кнопке
 * удалённого заказа отвечал «Заказ не найден», а кнопки оставались
 * кликабельными навсегда. См. OrderController::destroy().
 */
class OrderDestroyRemovesStaleKeyboardsTest extends TestCase
{
    private function orderForShop(Shop $shop): Order
    {
        TenantService::setContext($shop);

        return Order::create([
            'customer_name' => 'Тест',
            'customer_phone' => '+70000000000',
        ])->refresh();
    }

    private function staleTelegramPicker(Shop $shop, Order $order): void
    {
        TelegramMessage::create([
            'shop_id' => $shop->id,
            'telegram_chat_id' => $shop->telegram_chat_id,
            'telegram_message_id' => 555,
            'type' => 'system_notification',
            'entity_type' => 'Order',
            'entity_id' => $order->id,
            'message_text' => 'Старый пикер',
            'status' => 'sent',
        ]);
    }

    private function staleMaxPicker(Shop $shop, Order $order, string $mid = 'old-mid-123'): void
    {
        Cache::put("max_mid:order:{$order->id}", ['user_id' => $shop->max_chat_id, 'mid' => $mid], now()->addDays(7));
    }

    public function test_deleting_an_order_removes_the_stale_telegram_keyboard(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 999]])]);

        $shop = $this->createTestShop(['telegram_chat_id' => random_int(100_000_000, 999_999_999), 'telegram_bot_connected' => true]);
        $order = $this->orderForShop($shop);
        $this->staleTelegramPicker($shop, $order);

        $this->actingAs($shop->user, 'sanctum')
            ->deleteJson("/api/admin/orders/{$order->id}")
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'editMessageReplyMarkup')
            && $request['message_id'] === 555);
    }

    public function test_deleting_an_order_removes_the_stale_max_keyboard(): void
    {
        config(['services.max.bot_token' => 'test-token']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $shop = $this->createTestShop(['max_chat_id' => random_int(100_000_000, 999_999_999), 'max_bot_connected' => true]);
        $order = $this->orderForShop($shop);
        $this->staleMaxPicker($shop, $order);

        $this->actingAs($shop->user, 'sanctum')
            ->deleteJson("/api/admin/orders/{$order->id}")
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'messages')
            && str_contains($request->url(), 'old-mid-123'));
    }
}
