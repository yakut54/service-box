<?php

namespace Tests;

use App\Models\Shop;
use App\Models\ShopStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Живой разбор 2026-10-19: tearDown() дропал тенантную Postgres-схему,
     * но НИКОГДА не удалял саму строку в public.shops (и users) — за одну
     * сессию интенсивных прогонов набралось 856 "мёртвых" строк, указывающих
     * на уже снесённые схемы. Незаметно, пока ни один код не делал Shop::all()
     * по ВСЕМ магазинам сразу — первой об это споткнулась команда
     * CheckSurchargeDeadline (ровно так и нашли). Теперь createTestShop()/
     * staffMember() сами запоминают, что создали, и tearDown() убирает это
     * целиком — public.shops/public.users тоже, не только схему.
     */
    private array $testShopIds = [];
    private array $testUserIds = [];
    private array $testShopSchemas = [];

    /**
     * Магазин со своей тенантной схемой, готовый к работе — Shop::boot()
     * сам генерирует schema_name/api_key и вызывает
     * public.create_shop_schema() при создании (см. app/Models/Shop.php),
     * поэтому здесь не нужно ничего провижинить вручную.
     */
    protected function createTestShop(array $overrides = []): Shop
    {
        $user = User::create([
            'name' => 'Test Owner',
            'email' => 'test-' . Str::uuid() . '@example.test',
            'password' => bcrypt('password'),
        ]);

        $shop = Shop::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Test Shop',
        ], $overrides));

        $this->testShopSchemas[] = $shop->schema_name;
        $this->testShopIds[] = $shop->id;
        $this->testUserIds[] = $user->id;

        return $shop;
    }

    /**
     * Принятый сотрудник магазина (admin/collector) с собственным User —
     * раньше была одинаковая копия этого метода в OrderAssignmentFlowTest
     * и OrderAssignmentEndpointsTest (всегда role=collector); вынесено сюда
     * и параметризовано ролью, чтобы третья копия не завелась.
     */
    protected function staffMember(Shop $shop, string $name, string $role = 'collector'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => Str::lower($name) . '-' . Str::uuid() . '@example.test',
            'password' => bcrypt('password'),
        ]);
        ShopStaff::create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'role' => $role,
            'accepted_at' => now(),
        ]);

        $this->testUserIds[] = $user->id;

        return $user;
    }

    protected function tearDown(): void
    {
        // Сначала шопы (shop_staff каскадится через shop_id ON DELETE
        // CASCADE), потом пользователи (owner + staffMember()) — иначе
        // shops.user_id/shop_staff.user_id помешали бы удалить users.
        if (!empty($this->testShopIds)) {
            Shop::whereIn('id', $this->testShopIds)->delete();
        }
        if (!empty($this->testUserIds)) {
            User::whereIn('id', $this->testUserIds)->delete();
        }
        $this->testShopIds = [];
        $this->testUserIds = [];

        foreach ($this->testShopSchemas as $schema) {
            DB::statement('DROP SCHEMA IF EXISTS "' . $schema . '" CASCADE');
        }
        $this->testShopSchemas = [];

        parent::tearDown();
    }
}
