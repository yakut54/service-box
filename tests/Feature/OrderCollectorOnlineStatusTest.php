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

    /**
     * Живой разбор 2026-10-19: "Егорка не онлайн, а показывает онлайн" —
     * Sanctum-токен сам по себе никогда не истекает, закрыл вкладку/сел
     * телефон — токен остаётся в базе сколько угодно. "Токен есть" ≠
     * "сидит в приложении прямо сейчас". Воспроизводим ровно это: токен
     * реально существует, но не использовался (и создан) больше 5 минут
     * назад — именно та дыра, которую нашли живым тестом.
     */
    public function test_collector_with_a_stale_unused_token_is_reported_offline(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);

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
        $token = $staffUser->createToken('session');
        \Illuminate\Support\Facades\DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->id)
            ->update([
                'created_at' => now()->subMinutes(10),
                'last_used_at' => now()->subMinutes(10),
            ]);

        $order->claimBy($staffUser->id, 'Егорка');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->getJson("/api/admin/orders/{$order->id}")
            ->assertOk();

        $response->assertJsonPath('data.collector_is_online', false);
    }

    /**
     * Регрессия к самому фиксу: только что созданный токен ещё НЕ имеет
     * last_used_at (Sanctum проставляет его не при создании, а при первом
     * запросе ЭТИМ токеном) — без COALESCE(last_used_at, created_at) в
     * User::isOnline() человек, который только что вошёл, ошибочно
     * оказался бы "не в сети" ровно в тот момент, когда он точно онлайн.
     */
    public function test_collector_with_a_brand_new_token_and_no_last_used_at_yet_is_reported_online(): void
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
        $this->assertNull(
            \Illuminate\Support\Facades\DB::table('personal_access_tokens')
                ->where('tokenable_id', $staffUser->id)->value('last_used_at')
        );

        $order->claimBy($staffUser->id, 'Юлия');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->getJson("/api/admin/orders/{$order->id}")
            ->assertOk();

        $response->assertJsonPath('data.collector_is_online', true);
    }
}
