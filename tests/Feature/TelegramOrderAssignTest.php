<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderItem;
use App\Models\Shop;
use App\Models\ShopStaff;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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

        // Сначала confirm — он кэширует список кандидатов и только тогда в
        // кнопках появляется индекс, который assign умеет разобрать (кнопка
        // шлёт позицию в списке, а не сам userId — см. лимит callback_data
        // у Telegram в 64 байта, PLAN.md 2026-10-18).
        $this->sendCallback($shop->telegram_chat_id, "order:confirm:{$order->id}");
        // Владелец — всегда первый в списке (OrderCollectorCandidates::listFor()).
        $this->sendCallback($shop->telegram_chat_id, "order:assign:{$order->id}:0");

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($shop->user_id, $order->collector_id);
        $this->assertSame($shop->user->name, $order->collector_name);

        // webhook() всегда отвечает 200 даже если внутри упало исключение
        // (см. его try/catch) — assertOk() в sendCallback() само по себе
        // ничего не доказывает. Нужна прямая проверка, что лог активности
        // реально записался: именно на этом шаге раньше падало из-за
        // actor_role NOT NULL (баг 2026-10-18), а claimBy() к этому моменту
        // уже успевал закоммититься, и симптом внешне выглядел как "почти
        // всё сработало".
        $activity = OrderActivity::where('order_id', $order->id)->where('action', 'claimed')->first();
        $this->assertNotNull($activity);
        $this->assertSame('owner', $activity->actor_role);
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

        $this->sendCallback($shop->telegram_chat_id, "order:confirm:{$order->id}");
        // Владелец — индекс 0, единственный принятый сотрудник — индекс 1.
        $this->sendCallback($shop->telegram_chat_id, "order:assign:{$order->id}:1");

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($staffUser->id, $order->collector_id);
        $this->assertSame('Егорка', $order->collector_name);

        $activity = OrderActivity::where('order_id', $order->id)->where('action', 'claimed')->first();
        $this->assertNotNull($activity);
        $this->assertSame('collector', $activity->actor_role);
    }

    public function test_assign_without_prior_confirm_is_rejected(): void
    {
        $shop = $this->shopWithBot();
        $order = $this->physicalOrder($shop);

        // Без confirm список кандидатов никогда не кэшировался — индекс
        // ни на что не указывает, заказ не должен меняться.
        $this->sendCallback($shop->telegram_chat_id, "order:assign:{$order->id}:0");

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

        $this->sendCallback($shop->telegram_chat_id, "order:confirm:{$order->id}");
        // Между показом списка и нажатием сотрудника отозвали доступ —
        // resolve() должен это перепроверить, а не доверять кэшу вслепую.
        $staff->update(['accepted_at' => null]);
        $this->sendCallback($shop->telegram_chat_id, "order:assign:{$order->id}:1");

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

    /**
     * Сеть до api.telegram.org с сервера периодически рвётся (видно в
     * продакшен-логах регулярно) — editMessageReplyMarkup таймаутит,
     * но это НЕ должно мешать claimBy() записаться и финальному
     * sendMessage всё равно уйти (баг 2026-10-18: "в боте должна быть
     * обратная связь, а её нет" — removeKeyboard() ловил исключение раньше
     * никак, сбой обрывал всю оставшуюся последовательность в
     * handleOrderAssign()).
     */
    public function test_a_telegram_api_failure_on_one_step_does_not_block_the_rest(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake([
            '*editMessageReplyMarkup*' => fn () => throw new ConnectionException('cURL error 28: Connection timed out'),
            '*' => Http::response(['ok' => true]),
        ]);

        $shop = $this->shopWithBot();
        $order = $this->physicalOrder($shop);

        $this->sendCallback($shop->telegram_chat_id, "order:confirm:{$order->id}");
        $this->sendCallback($shop->telegram_chat_id, "order:assign:{$order->id}:0");

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($shop->user_id, $order->collector_id);

        $activity = OrderActivity::where('order_id', $order->id)->where('action', 'claimed')->first();
        $this->assertNotNull($activity);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    }
}
