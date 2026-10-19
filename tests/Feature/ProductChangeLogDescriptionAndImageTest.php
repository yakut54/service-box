<?php

namespace Tests\Feature;

use App\Models\ProductChange;
use App\Models\Shop;
use App\Services\TenantService;
use Tests\TestCase;

/**
 * Живой разбор 2026-10-20 — журнал изменений товара следил за названием/
 * ценой/категорией/видимостью/остатком, но не за описанием и главным фото
 * карточки. Расширено в ProductChangeLog::TRACKED_FIELDS.
 */
class ProductChangeLogDescriptionAndImageTest extends TestCase
{
    private function createPhysicalProduct(Shop $shop, array $overrides = []): string
    {
        $response = $this->actingAs($shop->user, 'sanctum')
            ->postJson('/api/admin/products', array_merge([
                'type' => 'physical',
                'name' => 'Товар',
                'price' => 10000,
                'physical' => ['sale_mode' => 'piece', 'stock_quantity' => 5],
            ], $overrides));
        $response->assertCreated();

        return $response->json('data.id');
    }

    public function test_changing_description_is_logged(): void
    {
        $shop = $this->createTestShop();
        $productId = $this->createPhysicalProduct($shop, ['description' => 'Старое описание']);

        $response = $this->actingAs($shop->user, 'sanctum')
            ->putJson("/api/admin/products/{$productId}", ['description' => 'Новое описание']);
        $response->assertOk();

        TenantService::setContext($shop);
        $row = ProductChange::where('field', 'description')->first();
        $this->assertNotNull($row);
        $this->assertSame('Старое описание', $row->old_value);
        $this->assertSame('Новое описание', $row->new_value);
    }

    public function test_changing_image_url_is_logged(): void
    {
        $shop = $this->createTestShop();
        $productId = $this->createPhysicalProduct($shop, ['image_url' => 'https://example.test/old.jpg']);

        $response = $this->actingAs($shop->user, 'sanctum')
            ->putJson("/api/admin/products/{$productId}", ['image_url' => 'https://example.test/new.jpg']);
        $response->assertOk();

        TenantService::setContext($shop);
        $row = ProductChange::where('field', 'image_url')->first();
        $this->assertNotNull($row);
        $this->assertSame('https://example.test/old.jpg', $row->old_value);
        $this->assertSame('https://example.test/new.jpg', $row->new_value);
    }

    public function test_leaving_description_and_image_unchanged_logs_nothing_for_them(): void
    {
        $shop = $this->createTestShop();
        $productId = $this->createPhysicalProduct($shop, ['description' => 'Описание', 'image_url' => 'https://example.test/pic.jpg']);

        $response = $this->actingAs($shop->user, 'sanctum')
            ->putJson("/api/admin/products/{$productId}", ['name' => 'Переименован']);
        $response->assertOk();

        TenantService::setContext($shop);
        $this->assertSame(0, ProductChange::whereIn('field', ['description', 'image_url'])->count());
    }
}
