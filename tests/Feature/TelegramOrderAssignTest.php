<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shop;
use App\Models\ShopStaff;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Telegram-бот: "Подтвердить" на физическом заказе теперь не пишет
 * processing сам — сначала показывает список сотрудников, и только выбор
 * конкретного человека (action=assign) пишет status+collector вместе через
 * Order::claimBy() (PLAN.md 2026-10-18, этап 3).
 */
class TelegramOrderAssignTest extends TestCase
{
    private function shopWithBot(): Shop
    {
        // chat_id должен быть уникальным на тест — тесты не оборачиваются в
        // транзакцию (см. TestCase), и webhook ищет шоп по telegram_chat_id:
        // одинаковый id у разных тестов находил бы шоп из уже отработавшего
        // теста, чья тенантная схема к этому моменту уже дропнута в tearDown().
        return $this->createTestShop([
            'telegram_chat_id' => random_int(100_000_000, 999_999_999),
            'telegram_bot_connected' => true,
        ]);
    }

    private function physicalOrder(Shop $shop): Order
    {
        TenantService::setContext($shop);

        $order = Order::create([
            'customer_name' => 'Тест',
            'customer_phone' => '+70000000000',
        ])->refresh();

        OrderItem::create([
            'order_id' => $order->id,
            'product_name' => 'Молоко',
            'product_type' => 'physical',
            'price' => 100,
            'quantity' => 1,
        ]);

        return $order;
    }

    private function sendCallback(int $chatId, string $data, int $messageId = 111): void
    {
        $this->postJson('/api/webhook/telegram', [
            'callback_query' => [
                'id' => 'cb-' . Str::uuid(),
                'data' => $data,
                'message' => [
                    'chat' => ['id' => $chatId],
                    'message_id' => $messageId,
                ],
            ],
        ])->assertOk();
    }

    public function test_confirm_on_physical_order_does_not_set_status_and_shows_picker(): void
    {
        $shop = $this->shopWithBot();
        $order = $this->physicalOrder($shop);

        $this->sendCallback($shop->telegram_chat_id, "order:confirm:{$order->id}");

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->collector_id);
    }

    public function test_assign_sets_status_and_collector_together_for_owner(): void
    {
        $shop = $this->shopWithBot();
        $order = $this->physicalOrder($shop);

        $this->sendCallback($shop->telegram_chat_id, "order:assign:{$order->id}:{$shop->user_id}");

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($shop->user_id, $order->collector_id);
        $this->assertSame($shop->user->name, $order->collector_name);
    }

    public function test_assign_sets_status_and_collector_together_for_accepted_staff(): void
    {
        $shop = $this->shopWithBot();
        $order = $this->physicalOrder($shop);

        $staffUser = User::create([
            'name' => 'Егорка',
            'email' => 'egorka-' . Str::uuid() . '@example.test',
            'password' => bcrypt('password'),
        ]);
        ShopStaff::create([
            'shop_id' => $shop->id,
            'user_id' => $staffUser->id,
            'role' => 'collector',
            'accepted_at' => now(),
        ]);

        $this->sendCallback($shop->telegram_chat_id, "order:assign:{$order->id}:{$staffUser->id}");

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($staffUser->id, $order->collector_id);
        $this->assertSame('Егорка', $order->collector_name);
    }

    public function test_assign_rejects_a_user_not_on_staff(): void
    {
        $shop = $this->shopWithBot();
        $order = $this->physicalOrder($shop);
        $strangerId = (string) Str::uuid();

        $this->sendCallback($shop->telegram_chat_id, "order:assign:{$order->id}:{$strangerId}");

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->collector_id);
    }

    public function test_digital_only_order_confirm_behavior_is_unchanged(): void
    {
        $shop = $this->shopWithBot();
        TenantService::setContext($shop);

        $order = Order::create([
            'customer_name' => 'Тест',
            'customer_phone' => '+70000000000',
        ])->refresh();

        OrderItem::create([
            'order_id' => $order->id,
            'product_name' => 'Электронный сертификат',
            'product_type' => 'digital',
            'price' => 100,
            'quantity' => 1,
        ]);

        $this->sendCallback($shop->telegram_chat_id, "order:confirm:{$order->id}");

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertNull($order->collector_id);
    }
}
