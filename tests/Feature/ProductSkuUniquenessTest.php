<?php

namespace Tests\Feature;

use App\Models\Shop;
use Tests\TestCase;

/**
 * Живой разбор 2026-10-19 — два товара с одним и тем же SKU через API
 * раньше создавались без единого отказа (ни валидации, ни БД-constraint).
 * Покрывает validation-слой (StoreProductRequest/UpdateProductRequest);
 * сам partial unique index в схеме проверен вручную на боевом шопе.
 */
class ProductSkuUniquenessTest extends TestCase
{
    private function createPhysicalProduct(Shop $shop, array $overrides = []): array
    {
        $response = $this->actingAs($shop->user, 'sanctum')
            ->postJson('/api/admin/products', array_merge([
                'type' => 'physical',
                'name' => 'Товар',
                'price' => 10000,
                'physical' => [
                    'sale_mode' => 'piece',
                    'stock_quantity' => 5,
                ],
            ], $overrides));

        return [$response, $response->json('data.id')];
    }

    public function test_store_rejects_a_duplicate_sku(): void
    {
        $shop = $this->createTestShop();

        [$first] = $this->createPhysicalProduct($shop, [
            'name' => 'Товар А',
            'physical' => ['sale_mode' => 'piece', 'stock_quantity' => 5, 'sku' => 'DUP-1'],
        ]);
        $first->assertCreated();

        [$second] = $this->createPhysicalProduct($shop, [
            'name' => 'Товар Б',
            'physical' => ['sale_mode' => 'piece', 'stock_quantity' => 5, 'sku' => 'DUP-1'],
        ]);
        $second->assertStatus(422);
        $this->assertSame(
            'Товар с таким SKU уже есть в магазине',
            $second->json('errors')['physical.sku'][0] ?? null,
        );
    }

    public function test_store_allows_an_empty_sku_to_repeat(): void
    {
        $shop = $this->createTestShop();

        [$first] = $this->createPhysicalProduct($shop, [
            'name' => 'Товар А',
            'physical' => ['sale_mode' => 'piece', 'stock_quantity' => 5, 'sku' => ''],
        ]);
        $first->assertCreated();

        [$second] = $this->createPhysicalProduct($shop, [
            'name' => 'Товар Б',
            'physical' => ['sale_mode' => 'piece', 'stock_quantity' => 5, 'sku' => ''],
        ]);
        $second->assertCreated();
    }

    public function test_update_allows_keeping_the_products_own_unchanged_sku(): void
    {
        $shop = $this->createTestShop();

        [$created, $productId] = $this->createPhysicalProduct($shop, [
            'name' => 'Товар А',
            'physical' => ['sale_mode' => 'piece', 'stock_quantity' => 5, 'sku' => 'SELF-1'],
        ]);
        $created->assertCreated();

        $response = $this->actingAs($shop->user, 'sanctum')
            ->putJson("/api/admin/products/{$productId}", [
                'name' => 'Товар А (переименован)',
                'physical' => ['sku' => 'SELF-1'],
            ]);

        $response->assertOk();
    }

    public function test_update_rejects_changing_sku_to_one_used_by_another_product(): void
    {
        $shop = $this->createTestShop();

        [$first] = $this->createPhysicalProduct($shop, [
            'name' => 'Товар А',
            'physical' => ['sale_mode' => 'piece', 'stock_quantity' => 5, 'sku' => 'TAKEN'],
        ]);
        $first->assertCreated();

        [$second, $secondId] = $this->createPhysicalProduct($shop, [
            'name' => 'Товар Б',
            'physical' => ['sale_mode' => 'piece', 'stock_quantity' => 5, 'sku' => 'FREE'],
        ]);
        $second->assertCreated();

        $response = $this->actingAs($shop->user, 'sanctum')
            ->putJson("/api/admin/products/{$secondId}", [
                'physical' => ['sku' => 'TAKEN'],
            ]);

        $response->assertStatus(422);
        $this->assertSame(
            'Товар с таким SKU уже есть в магазине',
            $response->json('errors')['physical.sku'][0] ?? null,
        );
    }
}
