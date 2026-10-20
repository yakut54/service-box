<?php

namespace Tests\Feature\Collector;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shop;
use App\Services\TenantService;
use Tests\TestCase;

/**
 * Живой разбор 2026-10-20 — заказ, помеченный needs_attention
 * (OrderController::reportProblem), не пускает дальше в collected
 * (Order::TRANSITIONS: needs_attention → только processing/cancelled).
 * Кнопка «Готово» у сборщика при этом выглядела активной и падала
 * необъяснённым 422. Фикс — на фронте (finishBlockedReason объясняет
 * причину + кнопка «Проблема решена» → processing), сам бэкенд не менялся.
 */
class CollectorFinishBlockedByProblemTest extends TestCase
{
    private function orderNeedsAttention(Shop $shop, string $collectorId): Order
    {
        TenantService::setContext($shop);

        $order = Order::create([
            'customer_name' => 'Тест',
            'customer_phone' => '+70000000000',
            'status' => 'needs_attention',
            'paid_at' => now(),
            'collector_id' => $collectorId,
            'collector_name' => 'Тест',
            'pick_note' => 'Картошка гнилая',
        ])->refresh();

        OrderItem::create([
            'order_id' => $order->id,
            'product_name' => 'Картофель молодой',
            'product_type' => 'physical',
            'price' => 100,
            'quantity' => 1,
            'picked_qty' => 1,
        ]);

        return $order;
    }

    public function test_needs_attention_order_cannot_jump_straight_to_collected(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderNeedsAttention($shop, $shop->user_id);

        $response = $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'collected']);

        $response->assertStatus(422);
    }

    public function test_owner_can_resolve_the_problem_back_to_processing(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderNeedsAttention($shop, $shop->user_id);

        $response = $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'processing']);

        $response->assertOk();

        TenantService::setContext($shop);
        $this->assertSame('processing', $order->refresh()->status);
    }

    /**
     * Известный смежный пробел, не этот баг и не фикс этого захода:
     * настоящему сборщику (role=collector) этот же эндпоинт вообще не даёт
     * поставить processing — валидация разрешает ему только
     * collected/completed (OrderController::updateStatus). Кнопка
     * «Проблема решена» на фронте поэтому скрыта для его роли — см.
     * CollectorOrderDetailView.vue. Фиксирую текущее поведение, не меняю его.
     */
    public function test_a_real_collector_still_cannot_set_processing_via_this_endpoint(): void
    {
        $shop = $this->createTestShop();
        $collector = $this->staffMember($shop, 'Егорка', 'collector');
        $order = $this->orderNeedsAttention($shop, $collector->id);

        $response = $this->actingAs($collector, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'processing']);

        $response->assertStatus(422);
    }
}
