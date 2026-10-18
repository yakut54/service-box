<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\Shop;
use App\Models\ShopStaff;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Веб-эндпоинты поверх OrderAssignmentFlow (PLAN.md 2026-10-19) — кнопка
 * «В работу» в обычной карточке заказа теперь проходит через тот же
 * request/accept/decline цикл, что и бот, не прямой updateStatus().
 */
class OrderAssignmentEndpointsTest extends TestCase
{
    private function orderForShop(Shop $shop, array $overrides = []): Order
    {
        TenantService::setContext($shop);

        return Order::create(array_merge([
            'customer_name' => 'Тест',
            'customer_phone' => '+70000000000',
        ], $overrides))->refresh();
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

    public function test_request_assignment_to_self_claims_immediately(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);

        $response = $this->actingAs($shop->user, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/request-assignment", ['candidate_id' => $shop->user_id])
            ->assertOk();

        $response->assertJsonPath('pending', false);

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($shop->user_id, $order->collector_id);
    }

    public function test_request_assignment_to_someone_else_online_creates_a_pending_request(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/request-assignment", ['candidate_id' => $staff->id])
            ->assertOk();

        $response->assertJsonPath('pending', true);

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->collector_id);
        $this->assertSame($staff->id, $order->pending_collector_id);
    }

    public function test_request_assignment_to_someone_offline_is_rejected(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        // Нет createToken() — Егорка нигде не залогинен.

        $this->actingAs($shop->user, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/request-assignment", ['candidate_id' => $staff->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Нельзя выбрать сотрудника, который сейчас не в сети');
    }

    /**
     * Живой тест 2026-10-19: "синхронизация есть, но не полная" — выбор
     * через веб уходил тихо мимо ботов, хотя тот же выбор через сам бот
     * туда сообщение шлёт. Telegram::sendMessage() кладёт запись в очередь
     * (TelegramMessage + job), не бьёт по сети напрямую — проверяем через
     * БД, не через Http::fake().
     */
    public function test_request_assignment_notifies_telegram_when_pending(): void
    {
        config(['services.telegram.bot_token' => 'test-token']);

        $shop = $this->createTestShop([
            'telegram_chat_id' => random_int(100_000_000, 999_999_999),
            'telegram_bot_connected' => true,
        ]);
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');

        $this->actingAs($shop->user, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/request-assignment", ['candidate_id' => $staff->id])
            ->assertOk();

        $message = TelegramMessage::where('shop_id', $shop->id)->latest()->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('запрос отправлен Егорка', $message->message_text);
    }

    public function test_request_assignment_notifies_max_when_pending(): void
    {
        config(['services.max.bot_token' => 'test-token']);
        Http::fake(['*' => Http::response(['ok' => true])]);

        $shop = $this->createTestShop([
            'max_chat_id' => random_int(100_000_000, 999_999_999),
            'max_bot_connected' => true,
        ]);
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');

        $this->actingAs($shop->user, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/request-assignment", ['candidate_id' => $staff->id])
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages')
            && str_contains($request['text'] ?? '', 'запрос отправлен Егорка'));
    }

    public function test_assignment_candidates_lists_owner_and_staff_with_online_status(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->getJson("/api/admin/orders/{$order->id}/assignment-candidates")
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $owner = collect($data)->firstWhere('id', $shop->user_id);
        $egorka = collect($data)->firstWhere('id', $staff->id);
        $this->assertFalse($owner['is_online']); // владелец сам не делал запрос, токена нет
        $this->assertTrue($egorka['is_online']);
    }

    public function test_accept_assignment_claims_the_order(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');

        $this->actingAs($shop->user, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/request-assignment", ['candidate_id' => $staff->id])
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/accept-assignment")
            ->assertOk();

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($staff->id, $order->collector_id);
        $this->assertNull($order->pending_collector_id);
    }

    public function test_accept_assignment_rejects_a_user_the_request_was_not_sent_to(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');
        $someoneElse = $this->staffMember($shop, 'Юлия');

        $this->actingAs($shop->user, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/request-assignment", ['candidate_id' => $staff->id])
            ->assertOk();

        $this->actingAs($someoneElse, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/accept-assignment")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Этот запрос не для вас');

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertNull($order->collector_id);
    }

    public function test_decline_assignment_clears_the_request_and_logs_activity(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');

        $this->actingAs($shop->user, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/request-assignment", ['candidate_id' => $staff->id])
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/decline-assignment")
            ->assertOk();

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->collector_id);
        $this->assertNull($order->pending_collector_id);
        $this->assertTrue(
            OrderActivity::where('order_id', $order->id)->where('action', 'assignment_declined')->exists()
        );
    }
}
