<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Shop;
use App\Models\SubscriptionPayment;
use App\Services\YooKassaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * GET /api/admin/subscription
     */
    public function getSubscriptionInfo(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        return response()->json([
            'plan'                => $shop->subscription_plan,
            'expires_at'          => $shop->subscription_expires_at,
            'is_active'           => $shop->hasActiveSubscription(),
            'is_expiring_soon'    => $shop->isSubscriptionExpiringSoon(),
            'days_until_expiration' => $shop->getDaysUntilExpiration(),
            'limits'              => $shop->getPlanLimits(),
        ]);
    }

    /**
     * GET /api/admin/subscription/payments
     */
    public function getPaymentHistory(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $payments = SubscriptionPayment::where('shop_id', $shop->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($payments);
    }

    /**
     * POST /api/admin/subscription/create-payment
     *
     * Создаёт платёж подписки в ЮКасса и возвращает payment_url для редиректа.
     * Использует глобальные credentials ServiceBox (YOOKASSA_SHOP_ID / YOOKASSA_SECRET_KEY).
     */
    public function createSubscriptionPayment(Request $request): JsonResponse
    {
        $request->validate([
            'plan'          => 'required|in:micro,start,business,pro',
            'period_months' => 'integer|min:1|max:12',
        ]);

        $shop         = $request->attributes->get('shop');
        $plan         = $request->input('plan');
        $periodMonths = $request->input('period_months', 1);

        $basePrice = $this->getPlanPrice($plan);
        $amount    = $periodMonths >= 12
            ? $basePrice * 10   // 2 месяца в подарок
            : $basePrice * $periodMonths;

        // Запись-заглушка с временным payment_id (обновим после ответа ЮКасса)
        $subscriptionPayment = SubscriptionPayment::create([
            'shop_id'    => $shop->id,
            'payment_id' => 'pending_' . Str::uuid(),
            'plan'       => $plan,
            'amount'     => $amount,
            'currency'   => 'RUB',
            'status'     => 'pending',
            'period_start' => now(),
            'period_end'   => now()->addMonths($periodMonths),
        ]);

        $returnUrl = rtrim(config('app.frontend_url'), '/') . '/subscription?paid=1';

        $planNames = [
            'micro'    => 'Micro',
            'start'    => 'Start',
            'business' => 'Business',
            'pro'      => 'Pro',
        ];

        try {
            $yooKassa = new YooKassaService();   // credentials из .env
            $payment  = $yooKassa->createPayment(
                $amount,
                "Подписка ServiceBox: тариф {$planNames[$plan]}, {$periodMonths} мес.",
                $returnUrl,
                [
                    'shop_id'                 => $shop->id,
                    'subscription_payment_id' => $subscriptionPayment->id,
                    'type'                    => 'subscription',
                ]
            );

            // Сохраняем реальный payment_id от ЮКасса
            $subscriptionPayment->update(['payment_id' => $payment['payment_id']]);

            return response()->json([
                'payment_id'    => $payment['payment_id'],
                'payment_url'   => $payment['payment_url'],
                'amount'        => $amount,
                'plan'          => $plan,
                'period_months' => $periodMonths,
            ]);

        } catch (\Throwable $e) {
            Log::error('YooKassa createSubscriptionPayment error', [
                'error'   => $e->getMessage(),
                'shop_id' => $shop->id,
            ]);

            // Удаляем заглушку при ошибке
            $subscriptionPayment->delete();

            return response()->json([
                'message' => 'Не удалось создать платёж. Попробуйте позже.',
            ], 502);
        }
    }

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

        $order = Order::findOrFail($orderId);

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Заказ уже оплачен или отменён'], 422);
        }

        // Если payment_url уже создан — вернуть существующий
        if ($order->payment_url && $order->payment_id) {
            return response()->json(['payment_url' => $order->payment_url]);
        }

        $returnUrl = rtrim(config('app.frontend_url'), '/') . '/payment/result?order_id=' . $order->id;

        try {
            $yooKassa = new YooKassaService($shop->yookassa_shop_id, $shop->yookassa_secret_key);
            $payment  = $yooKassa->createPayment(
                $order->total_price,
                "Заказ #{$order->id}",
                $returnUrl,
                [
                    'order_id' => $order->id,
                    'type'     => 'order',
                ]
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
     * POST /api/webhook/yookassa
     *
     * Обрабатывает webhook от ЮКасса.
     * Поддерживает два типа: subscription и order.
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
        $type     = $metadata['type'] ?? 'subscription';

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

        // ── Подписка ServiceBox ───────────────────────────────────
        $shopId                 = $metadata['shop_id'] ?? null;
        $subscriptionPaymentId  = $metadata['subscription_payment_id'] ?? null;

        if (!$shopId || !$subscriptionPaymentId) {
            return response()->json(['error' => 'Missing metadata'], 400);
        }

        $subscriptionPayment = SubscriptionPayment::lockForUpdate()->find($subscriptionPaymentId);

        if (!$subscriptionPayment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        if ($subscriptionPayment->status === 'succeeded') {
            return response()->json(['status' => 'already_processed']);
        }

        $subscriptionPayment->update([
            'status'   => 'succeeded',
            'paid_at'  => now(),
            'metadata' => $payment,
        ]);

        $shop = Shop::find($shopId);
        if ($shop) {
            $newExpiresAt = $subscriptionPayment->period_end;

            if ($shop->hasActiveSubscription() && $shop->subscription_expires_at->greaterThan(now())) {
                $newExpiresAt = $shop->subscription_expires_at->copy()
                    ->addMonths($subscriptionPayment->getPeriodDays() / 30);
            }

            $shop->update([
                'subscription_plan'       => $subscriptionPayment->plan,
                'subscription_expires_at' => $newExpiresAt,
            ]);
        }

        Log::info('Subscription activated via YooKassa', [
            'shop_id'  => $shopId,
            'plan'     => $subscriptionPayment->plan,
            'expires'  => $newExpiresAt ?? null,
        ]);

        return response()->json(['status' => 'success']);
    }

    private function getPlanPrice(string $plan): int
    {
        return match($plan) {
            'micro'    => 50000,
            'start'    => 100000,
            'business' => 150000,
            'pro'      => 300000,
            default    => 0,
        };
    }
}
