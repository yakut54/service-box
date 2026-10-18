<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Shop;
use App\Models\ShopStaff;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * collector_is_online — "Юлия назначена, но она вообще сейчас в сети?"
 * (живой разбор 2026-10-18). Источник истины — тот же, что уже используется
 * для "Активен" в разделе Команда (StaffController::index): живой
 * Sanctum-токен, не last_login_at (не отражает выход) и не WS-presence
 * (нужна открытая вкладка прямо сейчас).
 */
class OrderCollectorOnlineStatusTest extends TestCase
{
    private function orderForShop(Shop $shop): Order
    {
        TenantService::setContext($shop);

        return Order::create([
            'customer_name' => 'Тест',
            'customer_phone' => '+70000000000',
        ])->refresh();
    }

    public function test_collector_with_a_live_token_is_reported_online(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);

        $staffUser = User::create([
            'name' => 'Юлия',
            'email' => 'yulia-' . Str::uuid() . '@example.test',
            'password' => bcrypt('password'),
        ]);
        ShopStaff::create([
            'shop_id' => $shop->id,
            'user_id' => $staffUser->id,
            'role' => 'admin',
            'accepted_at' => now(),
        ]);
        $staffUser->createToken('session');

        $order->claimBy($staffUser->id, 'Юлия');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->getJson("/api/admin/orders/{$order->id}")
            ->assertOk();

        $response->assertJsonPath('data.collector_is_online', true);
    }

    public function test_collector_without_a_live_token_is_reported_offline(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);

        $staffUser = User::create([
            'name' => 'Юлия',
            'email' => 'yulia-' . Str::uuid() . '@example.test',
            'password' => bcrypt('password'),
        ]);
        ShopStaff::create([
            'shop_id' => $shop->id,
            'user_id' => $staffUser->id,
            'role' => 'admin',
            'accepted_at' => now(),
        ]);
        // Нет createToken() — аккаунт существует, но нигде не залогинен.

        $order->claimBy($staffUser->id, 'Юлия');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->getJson("/api/admin/orders/{$order->id}")
            ->assertOk();

        $response->assertJsonPath('data.collector_is_online', false);
    }

    public function test_unclaimed_order_has_no_online_status(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);

        $response = $this->actingAs($shop->user, 'sanctum')
            ->getJson("/api/admin/orders/{$order->id}")
            ->assertOk();

        $response->assertJsonPath('data.collector_is_online', null);
    }
}
