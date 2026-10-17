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
                // Сохраняем способ оплаты только там, где потом реально
                // может понадобиться доплата (см. OrderReweighService) —
                // чтобы попробовать списать её автоматически, без того
                // чтобы покупатель второй раз шёл платить по ссылке.
                savePaymentMethod: $hasWeightVariable ? true : null,
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

        // Автосписание уже в процессе (см. OrderReweighService::finalizeOrder)
        // — нельзя одновременно создать ещё и ручной платёж по ссылке, иначе
        // покупателя могут списать дважды.
        if ($order->surcharge_auto_charged_at !== null) {
            return response()->json(['message' => 'Доплата уже списывается автоматически, подождите подтверждения'], 422);
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

        // payment.waiting_for_capture нужен только заказам с холдом (type=order,
        // «по весу — перевзвешивание») — там это момент, когда карта уже
        // авторизована и можно забрать payment_method.id для будущего
        // автосписания доплаты (см. ветку type=order ниже). Остальным типам
        // (booking/order_surcharge) он не нужен — фильтр для них остаётся
        // ниже, как было.
        if (!in_array($event, ['payment.succeeded', 'payment.waiting_for_capture'], true)) {
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

        return TenantService::inContext($shop, function () use ($event, $type, $metadata, $payment) {
            // ── Предоплата за запись ──────────────────────────────────
            if ($type === 'booking') {
                if ($event !== 'payment.succeeded') {
                    return response()->json(['status' => 'ignored']);
                }

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

                // Холд авторизован, но ещё не списан (двухстадийная оплата
                // «по весу — перевзвешивание», см. OrderReweighService) —
                // карта уже привязана к платежу, сохраняем её ID для
                // будущего автосписания доплаты, если факт окажется больше
                // холда. Это НЕ оплата заказа — markAsPaid() здесь не зовём,
                // paid_at проставится позже отдельным payment.succeeded
                // (списание холда/капча).
                if ($event === 'payment.waiting_for_capture') {
                    $method = $payment['payment_method'] ?? [];
                    if (!empty($method['saved']) && !empty($method['id']) && !$order->payment_method_id) {
                        $order->update(['payment_method_id' => $method['id']]);
                        Log::info('Order payment method saved for future surcharge auto-charge', [
                            'order_id' => $orderId,
                            'payment_method_id' => $method['id'],
                        ]);
                    }

                    return response()->json(['status' => 'success']);
                }

                // Идемпотентность по факту оплаты (paid_at), не по стадии
                // заказа — раньше проверяли status==='pending', и как только
                // сборщик забирал заказ в работу (claim() переводит его в
                // processing безотносительно оплаты), более поздний вебхук
                // (в т.ч. списание холда после взвешивания — см.
                // OrderReweighService) считался "уже обработан" и
                // отбрасывался, paid_at не проставлялся никогда, хотя деньги
                // реально приходили. Найдено живым разбором 2026-10-17.
                if ($order->paid_at !== null) {
                    return response()->json(['status' => 'already_processed']);
                }

                $order->markAsPaid($payment['id']);

                Log::info('Order paid via YooKassa', ['order_id' => $orderId, 'payment_id' => $payment['id']]);

                return response()->json(['status' => 'success']);
            }

            // ── Доплата за перевзвешенный заказ ────────────────────────
            if ($type === 'order_surcharge') {
                if ($event !== 'payment.succeeded') {
                    return response()->json(['status' => 'ignored']);
                }

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
