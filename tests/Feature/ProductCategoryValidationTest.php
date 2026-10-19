<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Shop;
use App\Services\TenantService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Живой разбор 2026-10-19 — несуществующий category_id (или category_id из
 * чужого магазина) долетал до INSERT и падал foreign key violation'ом
 * (500 Server Error) вместо понятного 422.
 */
class ProductCategoryValidationTest extends TestCase
{
    private function categoryFor(Shop $shop, string $name): Category
    {
        TenantService::setContext($shop);
        return Category::create(['name' => $name, 'slug' => 'cat-' . Str::random(10), 'parent_id' => null]);
    }

    public function test_store_rejects_a_non_existent_category_id(): void
    {
        $shop = $this->createTestShop();

        $response = $this->actingAs($shop->user, 'sanctum')
            ->postJson('/api/admin/products', [
                'type' => 'physical',
                'name' => 'Товар',
                'price' => 10000,
                'category_id' => (string) Str::uuid(),
                'physical' => ['sale_mode' => 'piece', 'stock_quantity' => 5],
            ]);

        $response->assertStatus(422);
        $this->assertSame('Такой категории не существует', $response->json('errors.category_id')[0] ?? null);
    }

    public function test_store_accepts_a_real_category_id(): void
    {
        $shop = $this->createTestShop();
        $category = $this->categoryFor($shop, 'Одежда');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->postJson('/api/admin/products', [
                'type' => 'physical',
                'name' => 'Товар',
                'price' => 10000,
                'category_id' => $category->id,
                'physical' => ['sale_mode' => 'piece', 'stock_quantity' => 5],
            ]);

        $response->assertCreated();
    }

    public function test_store_rejects_a_category_id_belonging_to_a_different_shop(): void
    {
        $shop = $this->createTestShop();
        $otherShop = $this->createTestShop();
        $foreignCategory = $this->categoryFor($otherShop, 'Чужая категория');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->postJson('/api/admin/products', [
                'type' => 'physical',
                'name' => 'Товар',
                'price' => 10000,
                'category_id' => $foreignCategory->id,
                'physical' => ['sale_mode' => 'piece', 'stock_quantity' => 5],
            ]);

        $response->assertStatus(422);
        $this->assertSame('Такой категории не существует', $response->json('errors.category_id')[0] ?? null);
    }

    public function test_update_rejects_a_non_existent_category_id(): void
    {
        $shop = $this->createTestShop();
        $category = $this->categoryFor($shop, 'Одежда');

        $created = $this->actingAs($shop->user, 'sanctum')
            ->postJson('/api/admin/products', [
                'type' => 'physical',
                'name' => 'Товар',
                'price' => 10000,
                'category_id' => $category->id,
                'physical' => ['sale_mode' => 'piece', 'stock_quantity' => 5],
            ]);
        $created->assertCreated();

        $response = $this->actingAs($shop->user, 'sanctum')
            ->putJson("/api/admin/products/{$created->json('data.id')}", [
                'category_id' => (string) Str::uuid(),
            ]);

        $response->assertStatus(422);
        $this->assertSame('Такой категории не существует', $response->json('errors.category_id')[0] ?? null);
    }
}
