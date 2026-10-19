<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopStaff;
use App\Services\OrderAssignmentFlow;
use App\Services\OrderCollectorCandidates;
use App\Services\TenantService;
use Tests\TestCase;

/**
 * Живой разбор 2026-10-19 ("охота за дырами"): если сотрудник ограничен
 * категорией товаров (ShopStaff.category_ids — то же ограничение, что уже
 * фильтрует видимость самих заказов, см. OrderController::
 * applyCategoryScope()), его не должно быть ни в списке «кому делегировать»
 * заказ с товаром из ДРУГОЙ категории, ни среди тех, кого реально можно
 * выбрать через прямой вызов API в обход списка.
 */
class OrderCollectorCandidatesCategoryTest extends TestCase
{
    private function categoryFor(Shop $shop, string $name): Category
    {
        TenantService::setContext($shop);
        return Category::create(['name' => $name, 'slug' => 'cat-' . \Illuminate\Support\Str::random(10), 'parent_id' => null]);
    }

    private function orderWithProductIn(Shop $shop, Category $category): Order
    {
        TenantService::setContext($shop);

        $product = Product::create([
            'type' => 'physical',
            'name' => 'Кроссовки',
            'price' => 450000,
            'category_id' => $category->id,
        ]);

        $order = Order::create([
            'customer_name' => 'Тест',
            'customer_phone' => '+70000000000',
        ])->refresh();

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_type' => 'physical',
            'price' => $product->price,
            'quantity' => 1,
        ]);

        return $order->refresh();
    }

    public function test_admin_restricted_to_a_different_category_is_excluded_from_the_candidate_list(): void
    {
        $shop = $this->createTestShop();
        $sneakers = $this->categoryFor($shop, 'Обувь');
        $clothes = $this->categoryFor($shop, 'Одежда');
        $order = $this->orderWithProductIn($shop, $sneakers);

        $julia = $this->staffMember($shop, 'Юлия', 'admin');
        ShopStaff::where('user_id', $julia->id)->update(['category_ids' => [$clothes->id]]);

        $options = OrderCollectorCandidates::listFor($shop, $order);

        $this->assertNotContains($julia->id, array_column($options, 'id'));
    }

    public function test_collector_inherits_the_exclusion_from_their_admin(): void
    {
        $shop = $this->createTestShop();
        $sneakers = $this->categoryFor($shop, 'Обувь');
        $clothes = $this->categoryFor($shop, 'Одежда');
        $order = $this->orderWithProductIn($shop, $sneakers);

        $julia = $this->staffMember($shop, 'Юлия', 'admin');
        $juliaStaff = ShopStaff::where('user_id', $julia->id)->first();
        $juliaStaff->update(['category_ids' => [$clothes->id]]);

        $egorka = $this->staffMember($shop, 'Егорка', 'collector');
        ShopStaff::where('user_id', $egorka->id)->update(['admin_id' => $juliaStaff->id]);

        $options = OrderCollectorCandidates::listFor($shop, $order);

        $this->assertNotContains($julia->id, array_column($options, 'id'));
        $this->assertNotContains($egorka->id, array_column($options, 'id'));
    }

    public function test_admin_matching_the_order_category_is_included(): void
    {
        $shop = $this->createTestShop();
        $sneakers = $this->categoryFor($shop, 'Обувь');
        $order = $this->orderWithProductIn($shop, $sneakers);

        $julia = $this->staffMember($shop, 'Юлия', 'admin');
        ShopStaff::where('user_id', $julia->id)->update(['category_ids' => [$sneakers->id]]);

        $options = OrderCollectorCandidates::listFor($shop, $order);

        $this->assertContains($julia->id, array_column($options, 'id'));
    }

    public function test_unrestricted_admin_is_always_included(): void
    {
        $shop = $this->createTestShop();
        $sneakers = $this->categoryFor($shop, 'Обувь');
        $order = $this->orderWithProductIn($shop, $sneakers);

        $julia = $this->staffMember($shop, 'Юлия', 'admin');
        // category_ids не трогаем — остаётся null («без ограничений»).

        $options = OrderCollectorCandidates::listFor($shop, $order);

        $this->assertContains($julia->id, array_column($options, 'id'));
    }

    public function test_owner_is_never_excluded_by_category(): void
    {
        $shop = $this->createTestShop();
        $sneakers = $this->categoryFor($shop, 'Обувь');
        $order = $this->orderWithProductIn($shop, $sneakers);

        $options = OrderCollectorCandidates::listFor($shop, $order);

        $this->assertContains($shop->user_id, array_column($options, 'id'));
    }

    /**
     * Пустой/нераспознанный набор категорий заказа (нет товаров, товар без
     * категории) — список НЕ режем никого, безопаснее показать всех, чем
     * молча остаться без единого кандидата.
     */
    public function test_an_order_with_no_categorized_items_does_not_exclude_anyone(): void
    {
        $shop = $this->createTestShop();
        $clothes = $this->categoryFor($shop, 'Одежда');
        TenantService::setContext($shop);
        $order = Order::create(['customer_name' => 'Тест', 'customer_phone' => '+70000000000'])->refresh();

        $julia = $this->staffMember($shop, 'Юлия', 'admin');
        ShopStaff::where('user_id', $julia->id)->update(['category_ids' => [$clothes->id]]);

        $options = OrderCollectorCandidates::listFor($shop, $order);

        $this->assertContains($julia->id, array_column($options, 'id'));
    }

    /**
     * Фильтр в списке был бы только украшением UI без этой проверки —
     * прямой вызов request-assignment с candidate_id в обход пикера должен
     * отказывать точно так же.
     */
    public function test_request_rejects_a_candidate_outside_their_category_even_via_direct_api_call(): void
    {
        $shop = $this->createTestShop();
        $sneakers = $this->categoryFor($shop, 'Обувь');
        $clothes = $this->categoryFor($shop, 'Одежда');
        $order = $this->orderWithProductIn($shop, $sneakers);

        $julia = $this->staffMember($shop, 'Юлия', 'admin');
        ShopStaff::where('user_id', $julia->id)->update(['category_ids' => [$clothes->id]]);
        $julia->createToken('session');

        $result = OrderAssignmentFlow::request($order, $shop, $shop->user_id, $julia->id);

        $this->assertFalse($result['ok']);
        $this->assertSame('Этот сотрудник не занимается товарами этого заказа', $result['message']);

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertNull($order->pending_collector_id);
    }

    public function test_request_allows_a_candidate_matching_the_category_via_direct_api_call(): void
    {
        $shop = $this->createTestShop();
        $sneakers = $this->categoryFor($shop, 'Обувь');
        $order = $this->orderWithProductIn($shop, $sneakers);

        $julia = $this->staffMember($shop, 'Юлия', 'admin');
        ShopStaff::where('user_id', $julia->id)->update(['category_ids' => [$sneakers->id]]);
        $julia->createToken('session');

        $result = OrderAssignmentFlow::request($order, $shop, $shop->user_id, $julia->id);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['pending']);
    }

    /**
     * Самого себя выбрать можно всегда, даже вне категории — self-select
     * этой проверкой намеренно не тронут (осознанная граница из плана,
     * не self-claim).
     */
    public function test_self_select_bypasses_the_category_check(): void
    {
        $shop = $this->createTestShop();
        $sneakers = $this->categoryFor($shop, 'Обувь');
        $clothes = $this->categoryFor($shop, 'Одежда');
        $order = $this->orderWithProductIn($shop, $sneakers);

        $julia = $this->staffMember($shop, 'Юлия', 'admin');
        ShopStaff::where('user_id', $julia->id)->update(['category_ids' => [$clothes->id]]);

        $result = OrderAssignmentFlow::request($order, $shop, $julia->id, $julia->id);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['pending']);
    }
}
