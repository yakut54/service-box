<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Shop;
use App\Services\TenantService;
use Tests\TestCase;

/**
 * Журнал изменений категорий (создание/переименование/перемещение/
 * удаление) — вкладка «Категории» в «Журнале изменений», см. CLAUDE.md →
 * PLAN.md 2026-10-20. Калька ProductChangeLog, но для дерева категорий.
 */
class CategoryChangeLogTest extends TestCase
{
    private function categoryFor(Shop $shop, string $name, array $overrides = []): Category
    {
        TenantService::setContext($shop);
        return Category::create(array_merge([
            'name' => $name,
            'slug' => 'cat-' . \Illuminate\Support\Str::random(10),
            'parent_id' => null,
        ], $overrides));
    }

    public function test_creating_a_category_logs_a_created_row(): void
    {
        $shop = $this->createTestShop();

        $response = $this->actingAs($shop->user, 'sanctum')
            ->postJson('/api/admin/categories', ['name' => 'Обувь']);
        $response->assertCreated();

        TenantService::setContext($shop);
        $row = \App\Models\CategoryChange::first();
        $this->assertNotNull($row);
        $this->assertSame('created', $row->action);
        $this->assertSame('Обувь', $row->category_name);
        $this->assertSame($shop->user->name, $row->actor_name);
        $this->assertSame('owner', $row->actor_role);
    }

    public function test_renaming_a_category_logs_the_old_and_new_name(): void
    {
        $shop = $this->createTestShop();
        $category = $this->categoryFor($shop, 'Обувь');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/categories/{$category->id}", ['name' => 'Обувь и аксессуары']);
        $response->assertOk();

        TenantService::setContext($shop);
        $row = \App\Models\CategoryChange::where('field', 'name')->first();
        $this->assertNotNull($row);
        $this->assertSame('updated', $row->action);
        $this->assertSame('Обувь', $row->old_value);
        $this->assertSame('Обувь и аксессуары', $row->new_value);
    }

    public function test_moving_a_category_under_a_new_parent_logs_parent_names_not_uuids(): void
    {
        $shop = $this->createTestShop();
        $parent = $this->categoryFor($shop, 'Одежда');
        $category = $this->categoryFor($shop, 'Кроссовки');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/categories/{$category->id}", ['parent_id' => $parent->id]);
        $response->assertOk();

        TenantService::setContext($shop);
        $row = \App\Models\CategoryChange::where('field', 'parent_id')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->old_value);
        $this->assertSame('Одежда', $row->new_value);
    }

    public function test_hiding_a_category_logs_a_visibility_change(): void
    {
        $shop = $this->createTestShop();
        $category = $this->categoryFor($shop, 'Обувь');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->delete("/api/admin/categories/{$category->id}?action=hide");
        $response->assertOk();

        TenantService::setContext($shop);
        $row = \App\Models\CategoryChange::where('field', 'visible')->first();
        $this->assertNotNull($row);
        $this->assertSame('1', $row->old_value);
        $this->assertSame('0', $row->new_value);
    }

    public function test_deleting_a_category_logs_a_deleted_row_with_a_name_snapshot(): void
    {
        $shop = $this->createTestShop();
        $category = $this->categoryFor($shop, 'Обувь');

        $response = $this->actingAs($shop->user, 'sanctum')
            ->delete("/api/admin/categories/{$category->id}?action=delete");
        $response->assertOk();

        TenantService::setContext($shop);
        $row = \App\Models\CategoryChange::where('action', 'deleted')->first();
        $this->assertNotNull($row);
        $this->assertSame('Обувь', $row->category_name);
    }

    public function test_category_scoped_admin_cannot_manage_categories_so_nothing_is_logged(): void
    {
        $shop = $this->createTestShop();
        $category = $this->categoryFor($shop, 'Обувь');
        $admin = $this->staffMember($shop, 'Юлия', 'admin');

        TenantService::setContext($shop);
        \App\Models\ShopStaff::where('user_id', $admin->id)->update(['category_ids' => [$category->id]]);

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/categories/{$category->id}", ['name' => 'Переименовано']);
        $response->assertStatus(403);

        TenantService::setContext($shop);
        $this->assertSame(0, \App\Models\CategoryChange::count());
    }

    public function test_unrestricted_admin_can_rename_a_category_and_it_is_logged_with_admin_role(): void
    {
        $shop = $this->createTestShop();
        $category = $this->categoryFor($shop, 'Обувь');
        $admin = $this->staffMember($shop, 'Юлия', 'admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/categories/{$category->id}", ['name' => 'Переименовано']);
        $response->assertOk();

        TenantService::setContext($shop);
        $row = \App\Models\CategoryChange::where('field', 'name')->first();
        $this->assertNotNull($row);
        $this->assertSame('admin', $row->actor_role);
        $this->assertSame('Юлия', $row->actor_name);
    }
}
