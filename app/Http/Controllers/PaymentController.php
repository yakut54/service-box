<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Order;
use App\Models\Shop;
use App\Services\TenantService;
use App\Services\YooKassaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    /**
     * POST /api/widget/orders/{order}/payment
     *
     * Создаёт платёж для заказа покупателя.
     * Использует credentials конкретного шопа (yookassa_shop_id / yookassa_secret_key).
     */
    public function createOrderPayment(Request $request, string $orderId): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        if (!$shop || !$shop->yookassa_shop_id || !$shop->yookassa_secret_key) {
            return response()->json(['message' => 'Онлайн-оплата не настроена для этого магазина'], 422);
        }

        $order = Order::with('items.product.physical')->findOrFail($orderId);

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Заказ уже оплачен или отменён'], 422);
        }

        // Если payment_url уже создан — вернуть существующий
        if ($order->payment_url && $order->payment_id) {
            return response()->json(['payment_url' => $order->payment_url]);
        }

        $returnUrl = rtrim(config('app.frontend_url'), '/') . '/payment/result?order_id=' . $order->id;

        // Заказ с товаром «по весу — перевзвешивание» — холд (capture: false):
        // точная сумма известна только после того как сборщик реально взвесит
        // товар, до этого списывать нельзя (см. OrderReweighService).
        $hasWeightVariable = $order->items->contains(
            fn ($item) => $item->product?->physical?->sale_mode === 'weight_variable'
        );

        try {
            $yooKassa = new YooKassaService($shop->yookassa_shop_id, $shop->yookassa_secret_key);
            $payment  = $yooKassa->createPayment(
                $order->total_price / 100,
                "Заказ #{$order->id}",
                $returnUrl,
                [
                    'order_id' => $order->id,
                    'shop_id'  => $shop->id,
                    'type'     => 'order',
                ],
                capture: !$hasWeightVariable,
            );

            $order->update([
                'payment_id'  => $payment['payment_id'],
                'payment_url' => $payment['payment_url'],
            ]);

            return response()->json(['payment_url' => $payment['payment_url']]);

        } catch (\Throwable $e) {
            Log::error('YooKassa createOrderPayment error', [
                'error'    => $e->getMessage(),
                'order_id' => $orderId,
                'shop_id'  => $shop->id,
            ]);

            return response()->json(['message' => 'Не удалось создать платёж. Попробуйте позже.'], 502);
        }
    }

    /**
     * POST /api/widget/orders/{order}/surcharge-payment
     *
     * Отдельный (одностадийный) платёж на разницу — когда сборщик взвесил товар
     * и фактическая сумма оказалась больше суммы холда (см. OrderReweighService).
     */
    public function createOrderSurchargePayment(Request $request, string $orderId): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        if (!$shop || !$shop->yookassa_shop_id || !$shop->yookassa_secret_key) {
            return response()->json(['message' => 'Онлайн-оплата не настроена для этого магазина'], 422);
        }

        $order = Order::findOrFail($orderId);

        if ($order->surcharge_status !== 'pending') {
            return response()->json(['message' => 'Доплата не требуется или уже обработана'], 422);
        }

        // Если ссылка уже создана — вернуть существующую
        if ($order->surcharge_payment_url && $order->surcharge_payment_id) {
            return response()->json(['payment_url' => $order->surcharge_payment_url]);
        }

        $returnUrl = rtrim(config('app.frontend_url'), '/') . '/payment/result?order_id=' . $order->id . '&surcharge=1';

        try {
            $yooKassa = new YooKassaService($shop->yookassa_shop_id, $shop->yookassa_secret_key);
            $payment  = $yooKassa->createPayment(
                $order->surcharge_amount / 100,
                "Доплата за заказ #{$order->id}",
                $returnUrl,
                [
                    'order_id' => $order->id,
                    'shop_id'  => $shop->id,
                    'type'     => 'order_surcharge',
                ],
            );

            $order->update([
                'surcharge_payment_id'  => $payment['payment_id'],
                'surcharge_payment_url' => $payment['payment_url'],
            ]);

            return response()->json(['payment_url' => $payment['payment_url']]);

        } catch (\Throwable $e) {
            Log::error('YooKassa createOrderSurchargePayment error', [
                'error'    => $e->getMessage(),
                'order_id' => $orderId,
                'shop_id'  => $shop->id,
            ]);

            return response()->json(['message' => 'Не удалось создать платёж. Попробуйте позже.'], 502);
        }
    }

    /**
     * POST /api/widget/bookings/{booking}/payment
     *
     * Создаёт платёж предоплаты за запись.
     */
    public function createBookingPayment(Request $request, string $bookingId): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        if (!$shop || !$shop->yookassa_shop_id || !$shop->yookassa_secret_key) {
            return response()->json(['message' => 'Онлайн-оплата не настроена для этого магазина'], 422);
        }

        if (!$shop->prepayment_enabled) {
            return response()->json(['message' => 'Предоплата не включена'], 422);
        }

        $booking = Booking::with('service')->findOrFail($bookingId);

        if ($booking->paid_at) {
            return response()->json(['message' => 'Запись уже оплачена'], 422);
        }

        // Если ссылка уже создана — вернуть её
        if ($booking->payment_url && $booking->payment_id) {
            return response()->json(['payment_url' => $booking->payment_url]);
        }

        // Сумма: фиксированная предоплата или полная стоимость услуги
        $amount = $shop->prepayment_amount > 0
            ? $shop->prepayment_amount
            : ($booking->service?->price ?? 0);

        if ($amount <= 0) {
            return response()->json(['message' => 'Не удалось определить сумму оплаты'], 422);
        }

        $returnUrl = rtrim(config('app.frontend_url'), '/') . '/payment/result?booking_id=' . $booking->id;

        try {
            $yooKassa = new YooKassaService($shop->yookassa_shop_id, $shop->yookassa_secret_key);
            $payment  = $yooKassa->createPayment(
                $amount / 100,
                "Предоплата: {$booking->service?->name}",
                $returnUrl,
                [
                    'booking_id' => $booking->id,
                    'shop_id'    => $shop->id,
                    'type'       => 'booking',
                ]
            );

            $booking->update([
                'payment_id'  => $payment['payment_id'],
                'payment_url' => $payment['payment_url'],
            ]);

            return response()->json(['payment_url' => $payment['payment_url']]);

        } catch (\Throwable $e) {
            Log::error('YooKassa createBookingPayment error', [
                'error'      => $e->getMessage(),
                'booking_id' => $bookingId,
                'shop_id'    => $shop->id,
            ]);

            return response()->json(['message' => 'Не удалось создать платёж. Попробуйте позже.'], 502);
        }
    }

    /**
     * POST /api/webhook/yookassa
     *
     * Обрабатывает webhook от ЮКасса.
     * Поддерживает два типа: booking и order.
     */
    public function handleYooKassaWebhook(Request $request): JsonResponse
    {
        Log::info('YooKassa webhook received', ['body' => $request->all()]);

        $event   = $request->input('event');
        $payment = $request->input('object');

        if ($event !== 'payment.succeeded') {
            return response()->json(['status' => 'ignored']);
        }

        $metadata = $payment['metadata'] ?? [];
        $type     = $metadata['type'] ?? null;

        // Заказы/записи живут в тенантной схеме магазина (shop_<id>), не в public —
        // без переключения search_path Order::find()/Booking::find() ищут не там и
        // всегда возвращают null. metadata кладёт shop_id сам PaymentController при
        // создании платежа (см. createOrderPayment/createBookingPayment).
        $shopId = $metadata['shop_id'] ?? null;
        $shop   = $shopId ? Shop::find($shopId) : null;

        if (!$shop) {
            Log::error('YooKassa webhook: missing or unknown shop_id in metadata', ['metadata' => $metadata]);
            return response()->json(['error' => 'Missing or unknown shop_id in metadata'], 400);
        }

        return TenantService::inContext($shop, function () use ($type, $metadata, $payment) {
            // ── Предоплата за запись ──────────────────────────────────
            if ($type === 'booking') {
                $bookingId = $metadata['booking_id'] ?? null;

                if (!$bookingId) {
                    return response()->json(['error' => 'Missing booking_id in metadata'], 400);
                }

                $booking = Booking::lockForUpdate()->find($bookingId);

                if (!$booking) {
                    return response()->json(['error' => 'Booking not found'], 404);
                }

                if ($booking->paid_at) {
                    return response()->json(['status' => 'already_processed']);
                }

                $booking->markAsPaid($payment['id']);

                Log::info('Booking prepayment received via YooKassa', ['booking_id' => $bookingId, 'payment_id' => $payment['id']]);

                return response()->json(['status' => 'success']);
            }

            // ── Заказ покупателя ──────────────────────────────────────
            if ($type === 'order') {
                $orderId = $metadata['order_id'] ?? null;

                if (!$orderId) {
                    return response()->json(['error' => 'Missing order_id in metadata'], 400);
                }

                $order = Order::lockForUpdate()->find($orderId);

                if (!$order) {
                    return response()->json(['error' => 'Order not found'], 404);
                }

                if ($order->status !== 'pending') {
                    return response()->json(['status' => 'already_processed']);
                }

                $order->markAsPaid($payment['id']);

                Log::info('Order paid via YooKassa', ['order_id' => $orderId, 'payment_id' => $payment['id']]);

                return response()->json(['status' => 'success']);
            }

            // ── Доплата за перевзвешенный заказ ────────────────────────
            if ($type === 'order_surcharge') {
                $orderId = $metadata['order_id'] ?? null;

                if (!$orderId) {
                    return response()->json(['error' => 'Missing order_id in metadata'], 400);
                }

                $order = Order::lockForUpdate()->find($orderId);

                if (!$order) {
                    return response()->json(['error' => 'Order not found'], 404);
                }

                if ($order->surcharge_status !== 'pending') {
                    return response()->json(['status' => 'already_processed']);
                }

                $order->update([
                    'surcharge_status' => 'paid',
                    'commission_amount' => $order->commission_amount
                        + (int) round($order->surcharge_amount * config('platform.commission_percent') / 100),
                ]);

                Log::info('Order surcharge paid via YooKassa', ['order_id' => $orderId, 'payment_id' => $payment['id']]);

                return response()->json(['status' => 'success']);
            }

            return response()->json(['status' => 'ignored']);
        });
    }
}
