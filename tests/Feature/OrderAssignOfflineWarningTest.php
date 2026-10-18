<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shop;
use App\Models\ShopStaff;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Назначили через бота человека, которого физически нет на связи — сам он
 * об этом узнать не может (WS-уведомление теряется, если его нет в сети
 * прямо сейчас), а карточка заказа в админке покажет "не в сети" только
 * если кто-то туда СЛУЧАЙНО зайдёт. Живой разбор 2026-10-19: "Юлия если бы
 * не зашла в кабинет сборщика — проебали бы заказ". Текст подтверждения в
 * самом боте — то, что владелец гарантированно видит, поэтому пометка идёт
 * именно туда.
 */
class OrderAssignOfflineWarningTest extends TestCase
{
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

    private function staffMember(Shop $shop, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => Str::lower($name) . '-' . Str::uuid() . '@example.test',
            'password' => bcrypt('password'),
        ]);
        ShopStaff::create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'role' => 'collector',
            'accepted_at' => now(),
        ]);

        return $user;
    }

    public function test_telegram_confirmation_warns_when_the_assigned_staff_member_is_offline(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $shop = $this->createTestShop([
            'telegram_chat_id' => random_int(100_000_000, 999_999_999),
            'telegram_bot_connected' => true,
        ]);
        $order = $this->physicalOrder($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        // Нет createToken() — Егорка нигде не залогинен.

        $this->postJson('/api/webhook/telegram', ['callback_query' => [
            'id' => 'cb-' . Str::uuid(),
            'data' => "order:confirm:{$order->id}",
            'message' => ['chat' => ['id' => $shop->telegram_chat_id], 'message_id' => 111],
        ]])->assertOk();
        $this->postJson('/api/webhook/telegram', ['callback_query' => [
            'id' => 'cb-' . Str::uuid(),
            'data' => "order:assign:{$order->id}:1",
            'message' => ['chat' => ['id' => $shop->telegram_chat_id], 'message_id' => 111],
        ]])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['text'] ?? '', 'Егорка сейчас не в сети');
        });
    }

    public function test_telegram_confirmation_has_no_warning_when_the_assigned_staff_member_is_online(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $shop = $this->createTestShop([
            'telegram_chat_id' => random_int(100_000_000, 999_999_999),
            'telegram_bot_connected' => true,
        ]);
        $order = $this->physicalOrder($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');

        $this->postJson('/api/webhook/telegram', ['callback_query' => [
            'id' => 'cb-' . Str::uuid(),
            'data' => "order:confirm:{$order->id}",
            'message' => ['chat' => ['id' => $shop->telegram_chat_id], 'message_id' => 111],
        ]])->assertOk();
        $this->postJson('/api/webhook/telegram', ['callback_query' => [
            'id' => 'cb-' . Str::uuid(),
            'data' => "order:assign:{$order->id}:1",
            'message' => ['chat' => ['id' => $shop->telegram_chat_id], 'message_id' => 111],
        ]])->assertOk();

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'sendMessage')) {
                return false;
            }
            $text = $request['text'] ?? '';
            return str_contains($text, 'собирает Егорка') && !str_contains($text, 'не в сети');
        });
    }

    public function test_max_confirmation_warns_when_the_assigned_staff_member_is_offline(): void
    {
        config(['services.max.bot_token' => 'test-token']);
        config(['services.max.webhook_secret' => 'test-secret']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $shop = $this->createTestShop([
            'max_chat_id' => random_int(100_000_000, 999_999_999),
            'max_bot_connected' => true,
        ]);
        $order = $this->physicalOrder($shop);
        $this->staffMember($shop, 'Егорка');

        $this->postJson('/api/webhook/max/test-secret', [
            'update_type' => 'message_callback',
            'callback' => [
                'callback_id' => 'cb-' . Str::uuid(),
                'payload' => "order:confirm:{$order->id}",
                'user' => ['user_id' => $shop->max_chat_id],
                'message' => ['body' => ['mid' => 'mid-' . Str::uuid()]],
            ],
        ])->assertOk();
        $this->postJson('/api/webhook/max/test-secret', [
            'update_type' => 'message_callback',
            'callback' => [
                'callback_id' => 'cb-' . Str::uuid(),
                'payload' => "order:assign:{$order->id}:1",
                'user' => ['user_id' => $shop->max_chat_id],
                'message' => ['body' => ['mid' => 'mid-' . Str::uuid()]],
            ],
        ])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/messages')
                && str_contains($request['text'] ?? '', 'Егорка сейчас не в сети');
        });
    }
}
