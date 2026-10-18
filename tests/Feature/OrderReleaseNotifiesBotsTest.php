<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Shop;
use App\Services\TenantService;
use Tests\TestCase;

/**
 * release() теперь шлёт уведомление владельцу в Telegram/MAX ("X не взял
 * заказ") — симметрично уже существующему notifyOwnerOrderProblem()
 * (PLAN.md 2026-10-18, кнопка «Закрыть» на уведомлении о назначении).
 * Токены ботов в тестовом окружении пустые, поэтому сам HTTP не уходит
 * (см. TelegramService/MaxService::sendMessage — оба тихо выходят без
 * токена) — тест закрепляет, что сам путь уведомления не ломает release(),
 * когда у магазина подключён бот.
 */
class OrderReleaseNotifiesBotsTest extends TestCase
{
    public function test_release_with_telegram_connected_still_succeeds(): void
    {
        $shop = $this->createTestShop([
            'telegram_chat_id' => random_int(100_000_000, 999_999_999),
            'telegram_bot_connected' => true,
        ]);

        TenantService::setContext($shop);
        $order = Order::create([
            'customer_name' => 'Тест',
            'customer_phone' => '+70000000000',
        ])->refresh();
        $order->claimBy($shop->user_id, $shop->user->name);

        $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/release", ['note' => 'Не успеваю'])
            ->assertOk();

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('needs_attention', $order->status);
        $this->assertNull($order->collector_id);
    }

    public function test_release_with_max_connected_still_succeeds(): void
    {
        $shop = $this->createTestShop([
            'max_chat_id' => random_int(100_000_000, 999_999_999),
            'max_bot_connected' => true,
        ]);

        TenantService::setContext($shop);
        $order = Order::create([
            'customer_name' => 'Тест',
            'customer_phone' => '+70000000000',
        ])->refresh();
        $order->claimBy($shop->user_id, $shop->user->name);

        $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/release", ['note' => 'Не успеваю'])
            ->assertOk();

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('needs_attention', $order->status);
        $this->assertNull($order->collector_id);
    }
}
