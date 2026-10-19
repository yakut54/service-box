<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\Shop;
use App\Models\ShopStaff;
use App\Models\User;
use App\Events\OrderAssignmentRequested;
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

    /**
     * Живой разбор 2026-10-19: отказ кандидата менял карточку у инициатора
     * тихо, адресовать уведомление было нечем — pending_initiator_id не
     * существовал вообще. Актёр записи в истории — инициатор (это его
     * действие "предложил"), имя кандидата уходит в note.
     */
    public function test_request_stores_the_initiator_and_logs_activity_against_them(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');

        OrderAssignmentFlow::request($order, $shop, $shop->user_id, $staff->id);

        $order->refresh();
        $this->assertSame($shop->user_id, $order->pending_initiator_id);
        $this->assertSame($shop->user->name, $order->pending_initiator_name);

        $activity = OrderActivity::where('order_id', $order->id)->where('action', 'assignment_requested')->first();
        $this->assertNotNull($activity);
        $this->assertSame($shop->user_id, $activity->actor_user_id);
        $this->assertSame('Егорка', $activity->note);
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

        $result = OrderAssignmentFlow::accept($order, $shop, $staff->id);

        $this->assertTrue($result['ok']);
        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($staff->id, $order->collector_id);
        $this->assertNull($order->pending_collector_id);
        $this->assertNull($order->pending_collector_name);
        $this->assertNull($order->pending_assignment_requested_at);
        $this->assertTrue(OrderActivity::where('order_id', $order->id)->where('action', 'claimed')->exists());
    }

    public function test_accept_rejects_a_user_the_request_was_not_sent_to(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');
        OrderAssignmentFlow::request($order, $shop, $shop->user_id, $staff->id);
        $order->refresh();

        $result = OrderAssignmentFlow::accept($order, $shop, (string) Str::uuid());

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

        $result = OrderAssignmentFlow::decline($order, $shop, $staff->id);

        $this->assertTrue($result['ok']);
        $this->assertSame('Егорка', $result['decliner_name']);
        // initiator_id в ответе — единственный способ адресовать попап
        // "вам отказали" тому, кто предлагал (живой разбор 2026-10-19).
        $this->assertSame($shop->user_id, $result['initiator_id']);
        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->collector_id);
        $this->assertNull($order->pending_collector_id);
        $this->assertNull($order->pending_initiator_id);
        $this->assertTrue(OrderActivity::where('order_id', $order->id)->where('action', 'assignment_declined')->exists());
    }

    public function test_decline_rejects_a_user_the_request_was_not_sent_to(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');
        OrderAssignmentFlow::request($order, $shop, $shop->user_id, $staff->id);
        $order->refresh();

        $result = OrderAssignmentFlow::decline($order, $shop, (string) Str::uuid());

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

    /**
     * cancel() — живой разбор 2026-10-19: пользователь явно отказался от
     * авто-снятия зависшего запроса по таймеру ("никакой автоматики, решает
     * человек"), поэтому единственный способ снять его — эта ручная кнопка.
     */
    public function test_cancel_by_initiator_clears_the_pending_request(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');
        OrderAssignmentFlow::request($order, $shop, $shop->user_id, $staff->id);
        $order->refresh();

        $result = OrderAssignmentFlow::cancel($order, $shop, $shop->user_id);

        $this->assertTrue($result['ok']);
        $this->assertSame('Егорка', $result['candidate_name']);
        $order->refresh();
        $this->assertNull($order->pending_collector_id);
        $this->assertNull($order->pending_initiator_id);
        $this->assertSame('pending', $order->status);
        $this->assertTrue(OrderActivity::where('order_id', $order->id)->where('action', 'assignment_cancelled')->exists());
    }

    /**
     * Снять может и владелец/админ, не только тот, кто создавал запрос —
     * например, владелец видит, что его админ предложил заказ и подвис, и
     * снимает сам, не дожидаясь, пока админ вернётся.
     */
    public function test_cancel_by_owner_who_is_not_the_initiator_is_allowed(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $admin = $this->staffMember($shop, 'Админ');
        ShopStaff::where('user_id', $admin->id)->update(['role' => 'admin']);
        $admin->createToken('session');
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');
        OrderAssignmentFlow::request($order, $shop, $admin->id, $staff->id);
        $order->refresh();

        $result = OrderAssignmentFlow::cancel($order, $shop, $shop->user_id);

        $this->assertTrue($result['ok']);
        $order->refresh();
        $this->assertNull($order->pending_collector_id);
    }

    public function test_cancel_by_an_unrelated_collector_is_rejected(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $staff = $this->staffMember($shop, 'Егорка');
        $staff->createToken('session');
        $bystander = $this->staffMember($shop, 'Посторонний');
        OrderAssignmentFlow::request($order, $shop, $shop->user_id, $staff->id);
        $order->refresh();

        $result = OrderAssignmentFlow::cancel($order, $shop, $bystander->id);

        $this->assertFalse($result['ok']);
        $this->assertSame('Нельзя снять чужой запрос', $result['message']);
        $order->refresh();
        $this->assertNotNull($order->pending_collector_id);
    }

    public function test_cancel_when_nothing_is_pending_is_rejected(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);

        $result = OrderAssignmentFlow::cancel($order, $shop, $shop->user_id);

        $this->assertFalse($result['ok']);
        $this->assertSame('Запрос уже не висит', $result['message']);
    }

    /**
     * Попап кандидату должен показывать сводку заказа, не только номер —
     * живой разбор 2026-10-19: "Юлия не знает, что берёт, отвечает вслепую".
     * broadcastWith() сам достаёт заказ по id — проверяем именно его, а не
     * вызов dispatch(), иначе сводка осталась бы непроверенной.
     */
    public function test_assignment_requested_event_carries_the_order_summary(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop, [
            'total_price' => 123_45,
            'delivery_method' => 'courier',
        ]);
        $staff = $this->staffMember($shop, 'Егорка');

        $event = new OrderAssignmentRequested($shop->id, $order->id, $staff->id, 'Егорка', 'Ховар');
        $payload = $event->broadcastWith();

        $this->assertSame('Ховар', $payload['initiatorName']);
        $this->assertSame(0, $payload['itemsCount']);
        $this->assertSame(123_45, $payload['totalPrice']);
        $this->assertSame('courier', $payload['deliveryMethod']);
    }
}
