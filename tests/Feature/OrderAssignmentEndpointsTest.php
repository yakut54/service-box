<?php

namespace Tests\Feature;

use App\Events\OrderAssignmentDeclined;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\Shop;
use App\Models\TelegramMessage;
use App\Services\TenantService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
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

    /**
     * Живой разбор 2026-10-19: Юлия отказалась через веб-модалку, у Ховара
     * (инициатора) карточка тихо поменяла текст без всплывающего окна, хотя
     * бот уже показывал явное сообщение про отказ — попап для веба собран на
     * этом событии (OrderAssignmentDeclinedModal.vue слушает его и
     * фильтрует по initiatorId).
     */
    public function test_decline_assignment_broadcasts_a_popup_to_the_initiator(): void
    {
        Event::fake([OrderAssignmentDeclined::class]);

        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Юлия');
        $staff->createToken('session');

        $this->actingAs($shop->user, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/request-assignment", ['candidate_id' => $staff->id])
            ->assertOk();

        $this->actingAs($staff, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/decline-assignment")
            ->assertOk();

        Event::assertDispatched(OrderAssignmentDeclined::class, function (OrderAssignmentDeclined $event) use ($shop, $order) {
            return $event->shopId === $shop->id
                && $event->orderId === $order->id
                && $event->initiatorId === $shop->user_id
                && $event->declinerName === 'Юлия';
        });
    }

    public function test_show_returns_pending_candidate_online_status(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');

        $this->actingAs($shop->user, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/request-assignment", ['candidate_id' => $staff->id])
            ->assertOk();

        $response = $this->actingAs($shop->user, 'sanctum')
            ->getJson("/api/admin/orders/{$order->id}")
            ->assertOk();

        $response->assertJsonPath('data.pending_collector_is_online', true);
    }

    /**
     * cancel-assignment — кнопка "Отменить запрос" на карточке заказа,
     * живой разбор 2026-10-19: пользователь явно отказался от авто-снятия
     * зависшего запроса по таймеру, решение принимает человек.
     */
    public function test_cancel_assignment_by_initiator_clears_the_request(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');

        $this->actingAs($shop->user, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/request-assignment", ['candidate_id' => $staff->id])
            ->assertOk();

        $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/cancel-assignment")
            ->assertOk()
            ->assertJsonPath('message', 'Запрос снят');

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertNull($order->pending_collector_id);
        $this->assertTrue(
            OrderActivity::where('order_id', $order->id)->where('action', 'assignment_cancelled')->exists()
        );
    }

    /**
     * Отмена шлёт в бот тот же пикер, что и отказ кандидата ("Выберите
     * другого"), а не голый текст — переиспользуем collectorPickerRows,
     * не плодим вторую копию кнопок (PLAN.md 2026-10-19).
     */
    public function test_cancel_assignment_notifies_telegram_with_the_picker(): void
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

        $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/cancel-assignment")
            ->assertOk();

        $message = TelegramMessage::where('shop_id', $shop->id)->latest()->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('Егорка', $message->message_text);
        $this->assertStringContainsString('снят', $message->message_text);
    }

    public function test_cancel_assignment_notifies_max_with_the_picker(): void
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

        $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/cancel-assignment")
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages')
            && str_contains($request['text'] ?? '', 'Егорка')
            && str_contains($request['text'] ?? '', 'снят'));
    }

    public function test_cancel_assignment_by_an_unrelated_collector_is_rejected(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');
        $bystander = $this->staffMember($shop, 'Посторонний');
        $bystander->createToken('session');

        $this->actingAs($shop->user, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/request-assignment", ['candidate_id' => $staff->id])
            ->assertOk();

        $this->actingAs($bystander, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/cancel-assignment")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Нельзя снять чужой запрос');

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertNotNull($order->pending_collector_id);
    }
}
