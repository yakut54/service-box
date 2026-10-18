<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Shop;
use App\Services\TenantService;
use Tests\TestCase;

/**
 * PaymentController::createOrderPayment() — guard-логика вокруг реального
 * вызова ЮKassa SDK (который сам по себе лезет в сеть напрямую через
 * собственный curl-клиент библиотеки, не через Laravel Http facade, так что
 * Http::fake() его не перехватывает — мокать без рефакторинга на DI нельзя).
 * Эти три сценария срабатывают ДО создания YooKassaService и SDK вообще не
 * трогают, но именно они определяют, доходит ли запрос до реальной попытки
 * создать платёж (баг 2026-10-17 "shop всегда null на виджет-роуте" ломал
 * ИМЕННО эту логику — $shop был null, первая же проверка всегда давала 422,
 * независимо от того, настроена ли ЮKassa).
 */
class PaymentCreateOrderPaymentGuardsTest extends TestCase
{
    private function headers(Shop $shop): array
    {
        return ['X-Shop-ID' => $shop->api_key];
    }

    private function pendingOrder(Shop $shop, array $overrides = []): Order
    {
        TenantService::setContext($shop);

        return Order::create(array_merge([
            'customer_name' => 'Тест',
            'customer_phone' => '+70000000000',
        ], $overrides))->refresh();
    }

    public function test_returns_422_when_yookassa_is_not_configured_for_the_shop(): void
    {
        $shop = $this->createTestShop();
        $order = $this->pendingOrder($shop);

        $this->withHeaders($this->headers($shop))
            ->postJson("/api/widget/orders/{$order->id}/payment")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Онлайн-оплата не настроена для этого магазина');
    }

    public function test_returns_422_for_an_order_that_is_not_pending(): void
    {
        $shop = $this->createTestShop([
            'yookassa_shop_id' => '1499516',
            'yookassa_secret_key' => 'test_secret_key',
        ]);
        $order = $this->pendingOrder($shop, ['status' => 'paid']);

        $this->withHeaders($this->headers($shop))
            ->postJson("/api/widget/orders/{$order->id}/payment")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Заказ уже оплачен или отменён');
    }

    public function test_returns_the_existing_payment_url_without_creating_a_new_one(): void
    {
        $shop = $this->createTestShop([
            'yookassa_shop_id' => '1499516',
            'yookassa_secret_key' => 'test_secret_key',
        ]);
        $order = $this->pendingOrder($shop, [
            'payment_id' => 'already-created-id',
            'payment_url' => 'https://yoomoney.ru/checkout/payments/v2/already-created',
        ]);

        $this->withHeaders($this->headers($shop))
            ->postJson("/api/widget/orders/{$order->id}/payment")
            ->assertOk()
            ->assertJsonPath('payment_url', 'https://yoomoney.ru/checkout/payments/v2/already-created');
    }
}
