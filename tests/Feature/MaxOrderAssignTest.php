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
 * MAX-бот: симметрично TelegramOrderAssignTest — "Подтвердить" на
 * физическом заказе показывает список сотрудников, и только выбор
 * конкретного человека (action=assign) пишет status+collector вместе
 * через Order::claimBy() (PLAN.md 2026-10-18, этап 4).
 */
class MaxOrderAssignTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // VerifyMaxWebhook требует непустой секрет и сравнивает его с
        // {secret} в пути — без этого отдаёт 503 "Webhook не настроен".
        config(['services.max.webhook_secret' => 'test-secret']);
    }

    private function shopWithBot(): Shop
    {
        // user_id у MAX уникален на тест по той же причине, что и
        // telegram_chat_id у Telegram-теста — тесты не в транзакции, и
        // webhook ищет шоп по max_chat_id (см. TestCase::tearDown()).
        return $this->createTestShop([
            'max_chat_id' => random_int(100_000_000, 999_999_999),
            'max_bot_connected' => true,
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

    private function sendCallback(int $userId, string $payload): void
    {
        $this->postJson('/api/webhook/max/test-secret', [
            'update_type' => 'message_callback',
            'callback' => [
                'callback_id' => 'cb-' . Str::uuid(),
                'payload' => $payload,
                'user' => ['user_id' => $userId],
                'message' => ['body' => ['mid' => 'mid-' . Str::uuid()]],
            ],
        ])->assertOk();
    }

    public function test_confirm_on_physical_order_does_not_set_status(): void
    {
        $shop = $this->shopWithBot();
        $order = $this->physicalOrder($shop);

        $this->sendCallback($shop->max_chat_id, "order:confirm:{$order->id}");

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->collector_id);
    }

    public function test_assign_sets_status_and_collector_together_for_owner(): void
    {
        $shop = $this->shopWithBot();
        $order = $this->physicalOrder($shop);

        // Сначала confirm — кэширует список кандидатов, только тогда индекс
        // в assign на что-то указывает (кнопка шлёт позицию в списке, а не
        // userId напрямую — см. PLAN.md 2026-10-18, симметрично Telegram).
        $this->sendCallback($shop->max_chat_id, "order:confirm:{$order->id}");
        $this->sendCallback($shop->max_chat_id, "order:assign:{$order->id}:0");

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

        $this->sendCallback($shop->max_chat_id, "order:confirm:{$order->id}");
        $this->sendCallback($shop->max_chat_id, "order:assign:{$order->id}:1");

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($staffUser->id, $order->collector_id);
        $this->assertSame('Егорка', $order->collector_name);
    }

    public function test_assign_without_prior_confirm_is_rejected(): void
    {
        $shop = $this->shopWithBot();
        $order = $this->physicalOrder($shop);

        $this->sendCallback($shop->max_chat_id, "order:assign:{$order->id}:0");

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->collector_id);
    }

    public function test_assign_rejects_a_candidate_who_lost_access_after_confirm(): void
    {
        $shop = $this->shopWithBot();
        $order = $this->physicalOrder($shop);

        $staffUser = User::create([
            'name' => 'Егорка',
            'email' => 'egorka-' . Str::uuid() . '@example.test',
            'password' => bcrypt('password'),
        ]);
        $staff = ShopStaff::create([
            'shop_id' => $shop->id,
            'user_id' => $staffUser->id,
            'role' => 'collector',
            'accepted_at' => now(),
        ]);

        $this->sendCallback($shop->max_chat_id, "order:confirm:{$order->id}");
        $staff->update(['accepted_at' => null]);
        $this->sendCallback($shop->max_chat_id, "order:assign:{$order->id}:1");

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

        $this->sendCallback($shop->max_chat_id, "order:confirm:{$order->id}");

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertNull($order->collector_id);
    }
}
