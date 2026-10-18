<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\TenantService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Order::claimBy() — единственное место, которое пишет status и
 * collector_id ВМЕСТЕ (см. PLAN.md 2026-10-18, "В работе — может быть
 * только у кого-то"). Эти тесты закрепляют именно это: статус не может
 * стать processing без исполнителя, и наоборот — занятый заказ не отдаётся
 * чужому без явного takeover.
 */
class OrderClaimByTest extends TestCase
{
    private function freshOrder(array $overrides = []): Order
    {
        $shop = $this->createTestShop();
        TenantService::setContext($shop);

        // refresh() — иначе в памяти status остаётся null, а не дефолтное
        // 'pending' из самой БД (Eloquent не перечитывает дефолты колонок
        // после create() сам). В реальном коде claimBy() всегда зовётся на
        // уже свежепрочитанном через findOrFail() заказе, не прямо на
        // результате create() — здесь просто воспроизводим то же самое.
        return Order::create(array_merge([
            'customer_name' => 'Тест',
            'customer_phone' => '+70000000000',
        ], $overrides))->refresh();
    }

    public function test_free_order_sets_status_and_collector_together(): void
    {
        $order = $this->freshOrder();
        $userId = (string) Str::uuid();

        $result = $order->claimBy($userId, 'Юля');

        $this->assertTrue($result['ok']);
        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($userId, $order->collector_id);
        $this->assertSame('Юля', $order->collector_name);
        $this->assertNotNull($order->picking_started_at);
    }

    public function test_already_claimed_order_rejects_a_different_actor_without_takeover(): void
    {
        $order = $this->freshOrder();
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();
        $order->claimBy($first, 'Юля');

        $result = $order->claimBy($second, 'Егорка');

        $this->assertFalse($result['ok']);
        $this->assertSame('Юля', $result['collector_name']);
        $order->refresh();
        // Ничего не поменялось — вторая попытка не перезаписала первую.
        $this->assertSame($first, $order->collector_id);
        $this->assertSame('Юля', $order->collector_name);
    }

    public function test_takeover_lets_a_different_actor_reclaim(): void
    {
        $order = $this->freshOrder();
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();
        $order->claimBy($first, 'Юля');

        $result = $order->claimBy($second, 'Егорка', takeover: true);

        $this->assertTrue($result['ok']);
        $order->refresh();
        $this->assertSame($second, $order->collector_id);
        $this->assertSame('Егорка', $order->collector_name);
    }

    public function test_same_actor_reclaiming_own_order_is_a_no_op_success(): void
    {
        $order = $this->freshOrder();
        $userId = (string) Str::uuid();
        $order->claimBy($userId, 'Юля');

        $result = $order->claimBy($userId, 'Юля');

        $this->assertTrue($result['ok']);
    }

    public function test_closed_order_cannot_be_claimed(): void
    {
        $order = $this->freshOrder(['status' => 'completed']);

        $result = $order->claimBy((string) Str::uuid(), 'Юля');

        $this->assertFalse($result['ok']);
        $this->assertSame('Этот заказ уже закрыт', $result['message']);
        $order->refresh();
        $this->assertNull($order->collector_id);
    }

    /**
     * needs_attention попадает сюда только через release()/reportProblem()
     * — оба значат "кому-то нужно взять ответственность", и claim — это и
     * есть тот акт. Баг найден живым разбором 2026-10-18: статус оставался
     * "Требует внимания" даже после того, как заказ подхватил другой
     * сборщик.
     */
    public function test_needs_attention_order_resets_to_processing_on_claim(): void
    {
        $order = $this->freshOrder(['status' => 'needs_attention', 'pick_note' => 'Нога болит!']);

        $result = $order->claimBy((string) Str::uuid(), 'Егорка');

        $this->assertTrue($result['ok']);
        $order->refresh();
        $this->assertSame('processing', $order->status);
    }
}
