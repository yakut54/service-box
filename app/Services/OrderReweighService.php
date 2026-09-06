<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shop;
use Illuminate\Support\Facades\Log;

/**
 * Пересчёт заказа после того как сборщик взвесил товар «по весу —
 * перевзвешивание» (см. PLAN.md, «Режим «По весу — перевзвешивание»»).
 *
 * Намеренно НЕ вызывает Order::markAsPaid() напрямую — капча холда (см.
 * capturePayment ниже) сама провоцирует у ЮKassa новый payment.succeeded,
 * который прилетит на уже существующий (и уже починенный на тенантный
 * контекст) вебхук PaymentController::handleYooKassaWebhook и применит
 * markAsPaid() ровно как для обычного одностадийного заказа — переиспользуем
 * готовую идемпотентность вместо второй копии той же логики.
 */
class OrderReweighService
{
    /**
     * Сборщик вносит фактический вес одной позиции. Если это была последняя
     * невзвешенная weight_variable-позиция заказа — сразу считает итог и
     * списывает/довзыскивает деньги.
     */
    public static function submitActualWeight(OrderItem $item, int $actualGrams, Shop $shop): void
    {
        $product = $item->product;
        if (!$product) {
            abort(404, 'Товар не найден');
        }

        $actualPrice = (int) round($product->price * $actualGrams / 1000);

        $item->update([
            'actual_weight_grams' => $actualGrams,
            'actual_price' => $actualPrice,
        ]);

        // Списываем склад по факту — не при заказе (там для weight_variable
        // сознательный no-op, см. PhysicalStockService), а именно сейчас, когда
        // известно реальное количество. actualGrams=0 (сборщик пометил «товара
        // нет») просто ничего не списывает — это и есть способ отменить позицию,
        // отдельной кнопки «отменить» не требуется.
        $physical = $product->physical;
        if ($physical && $physical->sale_mode === 'weight_variable' && $actualGrams > 0) {
            if ($physical->stock_weight_grams < $actualGrams && !$physical->allow_backorder) {
                Log::warning('Weight-variable stock went negative on reweigh', [
                    'product_id' => $product->id,
                    'available' => $physical->stock_weight_grams,
                    'requested' => $actualGrams,
                ]);
            }
            $physical->decrement('stock_weight_grams', $actualGrams);
        }

        $order = $item->order;

        $stillPending = $order->items()
            ->whereHas('product.physical', fn ($q) => $q->where('sale_mode', 'weight_variable'))
            ->whereNull('actual_weight_grams')
            ->exists();

        if ($stillPending) {
            return;
        }

        self::finalizeOrder($order, $shop);
    }

    private static function finalizeOrder(Order $order, Shop $shop): void
    {
        $order->refresh();
        $order->load('items');

        $actualItemsTotal = $order->items->sum(
            fn ($item) => $item->actual_price ?? ($item->price * $item->quantity)
        );
        $actualTotal = $actualItemsTotal + $order->delivery_price;
        $heldAmount = $order->total_price;

        $yooKassa = ($shop->yookassa_shop_id && $shop->yookassa_secret_key)
            ? new YooKassaService($shop->yookassa_shop_id, $shop->yookassa_secret_key)
            : null;

        if ($actualTotal <= $heldAmount) {
            // Списываем ровно факт — остаток холда ЮKassa размораживает сама.
            // payment.succeeded от этого capture придёт на общий вебхук и там же
            // применит markAsPaid() с уже правильным total_price.
            if ($yooKassa && $order->payment_id) {
                try {
                    $yooKassa->capturePayment($order->payment_id, $actualTotal / 100);
                } catch (\Throwable $e) {
                    Log::error('YooKassa capturePayment failed on reweigh', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $order->update(['total_price' => $actualTotal, 'weighed_at' => $order->weighed_at ?? now()]);
            return;
        }

        // Факт больше холда — списываем то, что точно наше, остальное просим
        // доплатить отдельным платежом (см. PaymentController::createOrderSurchargePayment).
        $surcharge = $actualTotal - $heldAmount;

        if ($yooKassa && $order->payment_id) {
            try {
                $yooKassa->capturePayment($order->payment_id, $heldAmount / 100);
            } catch (\Throwable $e) {
                Log::error('YooKassa capturePayment (partial) failed on reweigh', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $order->update([
            'weighed_at' => $order->weighed_at ?? now(),
            'surcharge_amount' => $surcharge,
            'surcharge_status' => 'pending',
            'surcharge_requested_at' => now(),
            'surcharge_deadline_at' => now()->addHours(3),
        ]);

        self::notifySurcharge($order, $shop);
    }

    /** Все три канала — параллельно, каждый в своём try/catch, best-effort. */
    private static function notifySurcharge(Order $order, Shop $shop): void
    {
        try { TelegramService::notifySurchargeRequest($shop, $order); } catch (\Throwable $e) {
            Log::warning('notifySurchargeRequest (Telegram) failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
        try { MaxService::notifySurchargeRequest($shop, $order); } catch (\Throwable $e) {
            Log::warning('notifySurchargeRequest (MAX) failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
        try { MailService::notifySurcharge($shop, $order); } catch (\Throwable $e) {
            Log::warning('notifySurcharge (email) failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
        try { FirebaseService::notifySurcharge($order, $shop); } catch (\Throwable $e) {
            Log::warning('notifySurcharge (push) failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
