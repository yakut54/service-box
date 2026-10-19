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
     * Схемы тенантов, созданные через createTestShop() в этом тесте —
     * дропаются в tearDown(). Таблицы в public-схеме (shops/users) чистит
     * сам тест (здесь намеренно нет глобального DatabaseTransactions/
     * RefreshDatabase — у проекта нет миграций, "чистая" БД — это
     * database/schema/pgsql-schema.sql, прогнанный один раз при подъёме
     * тестовой БД, см. memory о тестовом окружении).
     */
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

        return $user;
    }

    protected function tearDown(): void
    {
        foreach ($this->testShopSchemas as $schema) {
            DB::statement('DROP SCHEMA IF EXISTS "' . $schema . '" CASCADE');
        }
        $this->testShopSchemas = [];

        parent::tearDown();
    }
}
