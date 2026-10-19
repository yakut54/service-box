<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Shop;
use App\Services\TenantService;
use Tests\TestCase;

/**
 * pick_note_by_name/pick_note_original_by_name — раньше заметка (pick_note)
 * не хранила автора вообще, только текст+дату; кто угодно, кто сейчас
 * владеет заказом, мог молча переписать чужую жалобу (живой разбор
 * 2026-10-18: Егорка взял заказ после отказа Юлии и мог отредактировать её
 * «Нога болит!» без следа). Эти тесты закрепляют единую атрибуцию через
 * OrderController::pickNoteUpdate(), общую для release()/reportProblem()/
 * updateStatus().
 */
class OrderPickNoteAttributionTest extends TestCase
{
    private function orderForShop(Shop $shop, array $overrides = []): Order
    {
        TenantService::setContext($shop);

        return Order::create(array_merge([
            'customer_name' => 'Тест',
            'customer_phone' => '+70000000000',
        ], $overrides))->refresh();
    }

    public function test_report_problem_records_the_author(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);

        $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/problem", ['note' => 'Нога болит!'])
            ->assertOk();

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('needs_attention', $order->status);
        $this->assertSame('Нога болит!', $order->pick_note);
        $this->assertSame($shop->user->name, $order->pick_note_by_name);
        $this->assertSame($shop->user->name, $order->pick_note_original_by_name);
    }

    public function test_editing_own_note_keeps_the_same_original_author(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);

        $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/problem", ['note' => 'Нога болит!'])
            ->assertOk();
        $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/problem", ['note' => 'Нога почти прошла'])
            ->assertOk();

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('Нога почти прошла', $order->pick_note);
        $this->assertSame($shop->user->name, $order->pick_note_by_name);
        $this->assertSame($shop->user->name, $order->pick_note_original_by_name);
        $this->assertNotNull($order->pick_note_edited_at);
    }

    public function test_a_different_staff_member_editing_the_note_keeps_the_original_author_but_updates_the_editor(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);

        $staffUser = $this->staffMember($shop, 'Егорка', 'admin');

        $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/problem", ['note' => 'Нога болит!'])
            ->assertOk();
        $this->actingAs($staffUser, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/problem", ['note' => 'Решили, заменяем заказ'])
            ->assertOk();

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('Решили, заменяем заказ', $order->pick_note);
        // Исходный автор не меняется — это он формально пожаловался первым.
        $this->assertSame($shop->user->name, $order->pick_note_original_by_name);
        // А последний редактор — тот, кто правил только что.
        $this->assertSame('Егорка', $order->pick_note_by_name);
    }

    public function test_release_records_the_author_too(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);
        $order->claimBy($shop->user_id, $shop->user->name);

        $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/release", ['note' => 'Не успеваю сегодня'])
            ->assertOk();

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('needs_attention', $order->status);
        $this->assertSame($shop->user->name, $order->pick_note_by_name);
        $this->assertSame($shop->user->name, $order->pick_note_original_by_name);
    }
}
