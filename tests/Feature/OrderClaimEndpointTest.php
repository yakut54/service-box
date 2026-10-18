<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Shop;
use App\Services\TenantService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PATCH /api/admin/orders/{order}/claim через настоящий HTTP-роут —
 * закрепляет, что рефакторинг claim() на Order::claimBy() (2026-10-18,
 * этап 2) не поменял поведение самой кнопки «Взять в работу» в админке.
 */
class OrderClaimEndpointTest extends TestCase
{
    private function orderForShop(Shop $shop, array $overrides = []): Order
    {
        TenantService::setContext($shop);

        return Order::create(array_merge([
            'customer_name' => 'Тест',
            'customer_phone' => '+70000000000',
        ], $overrides))->refresh();
    }

    public function test_owner_claims_a_pending_order_via_http(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop);

        $response = $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/claim");

        $response->assertOk();
        $response->assertJsonPath('message', 'Заказ взят в работу');

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame($shop->user->id, $order->collector_id);
        $this->assertSame($shop->user->name, $order->collector_name);
    }

    public function test_claim_conflict_without_takeover_returns_409(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop, [
            'collector_id' => (string) Str::uuid(),
            'collector_name' => 'Егорка',
        ]);

        $response = $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/claim");

        $response->assertStatus(409);
        $response->assertJsonPath('collector_name', 'Егорка');
    }

    public function test_claim_with_takeover_overrides_existing_collector(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop, [
            'collector_id' => (string) Str::uuid(),
            'collector_name' => 'Егорка',
        ]);

        $response = $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/claim?takeover=1");

        $response->assertOk();

        TenantService::setContext($shop);
        $order->refresh();
        $this->assertSame($shop->user->id, $order->collector_id);
        $this->assertSame($shop->user->name, $order->collector_name);
    }

    public function test_claiming_a_closed_order_returns_422(): void
    {
        $shop = $this->createTestShop();
        $order = $this->orderForShop($shop, ['status' => 'completed']);

        $response = $this->actingAs($shop->user, 'sanctum')
            ->patchJson("/api/admin/orders/{$order->id}/claim");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Этот заказ уже закрыт');
    }
}
