<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryChange;
use App\Models\Shop;
use App\Services\TenantService;
use Tests\TestCase;

/**
 * GET/DELETE /api/admin/category-changes — калька ProductChangeController,
 * см. CategoryChangeLogTest для самого журналирования (что и когда пишется).
 * Здесь строки журнала заводятся напрямую (не через CategoryController),
 * чтобы тестировать только чтение/фильтры/удаление, не само журналирование.
 */
class CategoryChangeControllerTest extends TestCase
{
    private function categoryFor(Shop $shop, string $name): Category
    {
        TenantService::setContext($shop);
        return Category::create(['name' => $name, 'slug' => 'cat-' . \Illuminate\Support\Str::random(10), 'parent_id' => null]);
    }

    private function loggedCreate(Shop $shop, string $name, \App\Models\User $actor, string $actorRole = 'owner'): Category
    {
        $category = $this->categoryFor($shop, $name);
        CategoryChange::create([
            'category_id'   => $category->id,
            'category_name' => $category->name,
            'action'        => 'created',
            'actor_user_id' => $actor->id,
            'actor_name'    => $actor->name,
            'actor_role'    => $actorRole,
        ]);

        return $category;
    }

    public function test_index_lists_category_changes_for_the_shop(): void
    {
        $shop = $this->createTestShop();
        $this->loggedCreate($shop, 'Обувь', $shop->user);

        $response = $this->actingAs($shop->user, 'sanctum')->getJson('/api/admin/category-changes');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('created', $response->json('data.0.action'));
    }

    public function test_pending_count_excludes_the_viewers_own_changes(): void
    {
        $shop = $this->createTestShop();
        $admin = $this->staffMember($shop, 'Юлия', 'admin');
        $this->loggedCreate($shop, 'Обувь', $shop->user);

        // Запись создана владельцем — для Юлии это «чужая» новая запись.
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/category-changes/pending-count');
        $response->assertOk();
        $this->assertSame(1, $response->json('count'));

        $this->actingAs($shop->user, 'sanctum')->getJson('/api/admin/category-changes/pending-count')
            ->assertOk()
            ->assertJson(['count' => 0]);
    }

    public function test_mark_seen_resets_the_pending_count(): void
    {
        $shop = $this->createTestShop();
        $admin = $this->staffMember($shop, 'Юлия', 'admin');
        $this->loggedCreate($shop, 'Обувь', $shop->user);

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/category-changes/mark-seen')->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/category-changes/pending-count')
            ->assertOk()
            ->assertJson(['count' => 0]);
    }

    public function test_only_owner_can_delete_category_change_rows(): void
    {
        $shop = $this->createTestShop();
        $admin = $this->staffMember($shop, 'Юлия', 'admin');
        $this->loggedCreate($shop, 'Обувь', $shop->user);

        TenantService::setContext($shop);
        $id = \App\Models\CategoryChange::first()->id;

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/admin/category-changes', ['ids' => [$id]])
            ->assertStatus(403);

        $this->actingAs($shop->user, 'sanctum')
            ->deleteJson('/api/admin/category-changes', ['ids' => [$id]])
            ->assertOk()
            ->assertJson(['deleted' => 1]);

        TenantService::setContext($shop);
        $this->assertSame(0, \App\Models\CategoryChange::count());
    }
}
