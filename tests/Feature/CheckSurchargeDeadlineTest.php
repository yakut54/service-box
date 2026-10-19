<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\Shop;
use App\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Живой разбор 2026-10-19: крон CheckSurchargeDeadline переводил заказ в
 * needs_attention сырым SQL, минуя OrderActivityLog — заметка (pick_note)
 * была видна, а "История заказа" оставалась пустой, хотя у остальных путей
 * в needs_attention (release()/reportProblem()) запись пишется всегда.
 */
class CheckSurchargeDeadlineTest extends TestCase
{
    private function orderWithExpiredSurcharge(Shop $shop, array $overrides = []): Order
    {
        TenantService::setContext($shop);

        return Order::create(array_merge([
            'customer_name' => 'Тест',
            'customer_phone' => '+70000000000',
            'status' => 'collected',
            'surcharge_status' => 'pending',
            'surcharge_amount' => 50000,
            'surcharge_deadline_at' => now()->subHour(),
        ], $overrides))->refresh();
    }

    public function test_expired_surcharge_moves_order_to_needs_attention_and_logs_activity(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderWithExpiredSurcharge($shop);

        $this->artisan('orders:check-surcharge-deadline')->assertExitCode(0);

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('needs_attention', $order->status);
        $this->assertSame('expired', $order->surcharge_status);
        $this->assertSame('Доплата за перевес не подтверждена вовремя — дедлайн истёк', $order->pick_note);

        $activity = OrderActivity::where('order_id', $order->id)->where('action', 'status_changed')->first();
        $this->assertNotNull($activity, 'Переход в needs_attention должен попасть в историю заказа');
        $this->assertSame('collected', $activity->from_status);
        $this->assertSame('needs_attention', $activity->to_status);
        $this->assertSame('Доплата за перевес не подтверждена вовремя — дедлайн истёк', $activity->note);
        $this->assertSame('Система', $activity->actor_name);
        $this->assertNull($activity->actor_user_id);
    }

    public function test_order_with_a_future_deadline_is_left_untouched(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderWithExpiredSurcharge($shop, ['surcharge_deadline_at' => now()->addHour()]);

        $this->artisan('orders:check-surcharge-deadline')->assertExitCode(0);

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('collected', $order->status);
        $this->assertSame('pending', $order->surcharge_status);
        $this->assertSame(0, OrderActivity::where('order_id', $order->id)->count());
    }

    public function test_order_without_a_pending_surcharge_is_left_untouched(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderWithExpiredSurcharge($shop, ['surcharge_status' => null, 'surcharge_deadline_at' => null]);

        $this->artisan('orders:check-surcharge-deadline')->assertExitCode(0);

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('collected', $order->status);
    }

    public function test_clears_order_seen_so_everyone_notices_it_again(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderWithExpiredSurcharge($shop);

        TenantService::setContext($shop);
        DB::table('order_seen')->insert([
            'order_id' => $order->id,
            'user_id' => $shop->user_id,
            'seen_at' => now(),
        ]);

        $this->artisan('orders:check-surcharge-deadline')->assertExitCode(0);

        TenantService::setContext($shop);
        $this->assertSame(0, DB::table('order_seen')->where('order_id', $order->id)->count());
    }
}
