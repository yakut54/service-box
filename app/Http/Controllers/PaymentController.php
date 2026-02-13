<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\SubscriptionPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * Get subscription info
     *
     * GET /api/admin/subscription
     */
    public function getSubscriptionInfo(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        return response()->json([
            'plan' => $shop->subscription_plan,
            'expires_at' => $shop->subscription_expires_at,
            'is_active' => $shop->hasActiveSubscription(),
            'is_expiring_soon' => $shop->isSubscriptionExpiringSoon(),
            'days_until_expiration' => $shop->getDaysUntilExpiration(),
            'limits' => $shop->getPlanLimits(),
        ]);
    }

    /**
     * Get payment history
     *
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
     * Create subscription payment
     *
     * POST /api/admin/subscription/create-payment
     */
    public function createSubscriptionPayment(Request $request): JsonResponse
    {
        $request->validate([
            'plan' => 'required|in:micro,start,business,pro',
            'period_months' => 'integer|min:1|max:12',
        ]);

        $shop = $request->attributes->get('shop');
        $plan = $request->input('plan');
        $periodMonths = $request->input('period_months', 1);

        $basePrice = $this->getPlanPrice($plan);
        $amount = $basePrice * $periodMonths;

        // Скидка за годовую подписку (2 месяца в подарок)
        if ($periodMonths >= 12) {
            $amount = $basePrice * 10;
        }

        $subscriptionPayment = SubscriptionPayment::create([
            'shop_id' => $shop->id,
            'payment_id' => 'pending_' . Str::uuid(),
            'plan' => $plan,
            'amount' => $amount,
            'currency' => 'RUB',
            'status' => 'pending',
            'period_start' => now(),
            'period_end' => now()->addMonths($periodMonths),
        ]);

        // TODO: Integrate with YooKassa API
        // For now return stub response
        return response()->json([
            'payment_id' => $subscriptionPayment->payment_id,
            'amount' => $amount,
            'plan' => $plan,
            'period_months' => $periodMonths,
            'message' => 'Payment gateway integration pending',
        ]);
    }

    /**
     * YooKassa webhook handler
     *
     * POST /api/webhook/yookassa
     */
    public function handleYooKassaWebhook(Request $request): JsonResponse
    {
        Log::info('YooKassa webhook received', [
            'body' => $request->all(),
        ]);

        $event = $request->input('event');
        $payment = $request->input('object');

        if ($event !== 'payment.succeeded') {
            return response()->json(['status' => 'ignored']);
        }

        $metadata = $payment['metadata'] ?? [];
        $shopId = $metadata['shop_id'] ?? null;
        $subscriptionPaymentId = $metadata['subscription_payment_id'] ?? null;

        if (!$shopId || !$subscriptionPaymentId) {
            return response()->json(['error' => 'Missing metadata'], 400);
        }

        $subscriptionPayment = SubscriptionPayment::lockForUpdate()
            ->find($subscriptionPaymentId);

        if (!$subscriptionPayment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        if ($subscriptionPayment->status === 'succeeded') {
            return response()->json(['status' => 'already_processed']);
        }

        $subscriptionPayment->update([
            'status' => 'succeeded',
            'paid_at' => now(),
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
                'subscription_plan' => $subscriptionPayment->plan,
                'subscription_expires_at' => $newExpiresAt,
            ]);
        }

        return response()->json(['status' => 'success']);
    }

    private function getPlanPrice(string $plan): int
    {
        return match($plan) {
            'micro' => 50000,
            'start' => 100000,
            'business' => 150000,
            'pro' => 300000,
            default => 0,
        };
    }
}
