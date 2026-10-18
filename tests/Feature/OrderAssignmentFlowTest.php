<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopStaff;
use App\Models\User;
use App\Services\OrderAssignmentFlow;
use App\Services\TenantService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OrderAssignmentFlow — единая точка "выбрали кандидата", общая для бота и
 * веб-админки (PLAN.md 2026-10-19). Выбор себя — мгновенный claim (спрашивать
 * подтверждения не у кого), выбор другого онлайн — запрос, ждущий accept/
 * decline, выбор офлайн — отказ сразу.
 */
class OrderAssignmentFlowTest extends TestCase
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

    public function test_choosing_yourself_claims_immediately_without_a_pending_request(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);

        $result = OrderAssignmentFlow::request($order, $shop, $shop->user_id, $shop->user_id);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['pending']);
        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($shop->user_id, $order->collector_id);
        $this->assertNull($order->pending_collector_id);
    }

    public function test_choosing_someone_else_who_is_online_creates_a_pending_request_without_claiming(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');

        $result = OrderAssignmentFlow::request($order, $shop, $shop->user_id, $staff->id);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['pending']);
        $order->refresh();
        // Ключевое: claim ЕЩЁ не произошёл — статус и collector_id нетронуты.
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->collector_id);
        $this->assertSame($staff->id, $order->pending_collector_id);
        $this->assertSame('Егорка', $order->pending_collector_name);
        $this->assertNotNull($order->pending_assignment_requested_at);
    }

    public function test_choosing_someone_who_is_offline_is_rejected(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        // Нет createToken() — Егорка нигде не залогинен.

        $result = OrderAssignmentFlow::request($order, $shop, $shop->user_id, $staff->id);

        $this->assertFalse($result['ok']);
        $this->assertSame('Нельзя выбрать сотрудника, который сейчас не в сети', $result['message']);
        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->pending_collector_id);
    }

    public function test_accept_claims_the_order_and_clears_the_pending_request(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');
        OrderAssignmentFlow::request($order, $shop, $shop->user_id, $staff->id);
        $order->refresh();

        $result = OrderAssignmentFlow::accept($order, $staff->id, 'Егорка');

        $this->assertTrue($result['ok']);
        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($staff->id, $order->collector_id);
        $this->assertNull($order->pending_collector_id);
        $this->assertNull($order->pending_collector_name);
        $this->assertNull($order->pending_assignment_requested_at);
    }

    public function test_accept_rejects_a_user_the_request_was_not_sent_to(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');
        OrderAssignmentFlow::request($order, $shop, $shop->user_id, $staff->id);
        $order->refresh();

        $result = OrderAssignmentFlow::accept($order, (string) Str::uuid(), 'Кто-то другой');

        $this->assertFalse($result['ok']);
        $this->assertSame('Этот запрос не для вас', $result['message']);
        $order->refresh();
        $this->assertNull($order->collector_id);
        $this->assertSame($staff->id, $order->pending_collector_id);
    }

    public function test_decline_clears_the_pending_request_without_claiming(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');
        OrderAssignmentFlow::request($order, $shop, $shop->user_id, $staff->id);
        $order->refresh();

        $result = OrderAssignmentFlow::decline($order, $staff->id);

        $this->assertTrue($result['ok']);
        $this->assertSame('Егорка', $result['decliner_name']);
        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->collector_id);
        $this->assertNull($order->pending_collector_id);
    }

    public function test_decline_rejects_a_user_the_request_was_not_sent_to(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');
        OrderAssignmentFlow::request($order, $shop, $shop->user_id, $staff->id);
        $order->refresh();

        $result = OrderAssignmentFlow::decline($order, (string) Str::uuid());

        $this->assertFalse($result['ok']);
        $order->refresh();
        $this->assertSame($staff->id, $order->pending_collector_id);
    }

    public function test_requesting_someone_else_on_a_closed_order_is_rejected(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop, ['status' => 'completed']);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');

        $result = OrderAssignmentFlow::request($order, $shop, $shop->user_id, $staff->id);

        $this->assertFalse($result['ok']);
        $this->assertSame('Этот заказ уже закрыт', $result['message']);
    }
}
