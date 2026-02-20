<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\DiscountUse;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

class DiscountService
{
    /**
     * Validate a promo code against the current cart.
     *
     * @throws ValidationException
     * @return array{discount: Discount, amount: int}
     */
    public function validate(string $code, int $cartAmount, ?string $customerPhone = null): array
    {
        $discount = Discount::active()
            ->whereNotNull('code')
            ->whereRaw('LOWER(code) = ?', [strtolower(trim($code))])
            ->first();

        if (!$discount) {
            throw ValidationException::withMessages([
                'discount_code' => 'Промокод не найден или недействителен',
            ]);
        }

        $this->assertUsable($discount, $cartAmount, $customerPhone);

        return [
            'discount' => $discount,
            'amount'   => $this->calculate($discount, $cartAmount),
        ];
    }

    /**
     * Find the best auto-apply (codeless) discount for this cart.
     */
    public function findAutoApply(int $cartAmount, ?string $customerPhone = null): ?Discount
    {
        $discounts = Discount::active()
            ->whereNull('code')
            ->where('min_order_amount', '<=', $cartAmount)
            ->get();

        $best       = null;
        $bestAmount = 0;

        foreach ($discounts as $discount) {
            try {
                $this->assertUsable($discount, $cartAmount, $customerPhone);
            } catch (ValidationException) {
                continue;
            }

            $amount = $this->calculate($discount, $cartAmount);
            if ($amount > $bestAmount) {
                $bestAmount = $amount;
                $best       = $discount;
            }
        }

        return $best;
    }

    /**
     * Calculate discount amount in kopecks for a given cart total.
     */
    public function calculate(Discount $discount, int $cartAmount): int
    {
        $amount = $discount->type === 'percent'
            ? (int) round($cartAmount * $discount->value / 100)
            : $discount->value;

        // Apply cap
        if ($discount->max_discount_amount !== null) {
            $amount = min($amount, $discount->max_discount_amount);
        }

        // Cannot exceed cart amount (no negative totals)
        return min($amount, $cartAmount);
    }

    /**
     * Record a discount use after the order has been saved.
     */
    public function recordUse(Discount $discount, Order $order): void
    {
        DiscountUse::create([
            'discount_id'     => $discount->id,
            'order_id'        => $order->id,
            'customer_phone'  => $order->customer_phone,
            'discount_amount' => $order->discount_amount,
        ]);

        $discount->increment('usage_count');
    }

    /**
     * Assert that a discount can be used. Throws ValidationException if not.
     *
     * @throws ValidationException
     */
    private function assertUsable(Discount $discount, int $cartAmount, ?string $customerPhone): void
    {
        if ($cartAmount < $discount->min_order_amount) {
            $minRub = number_format($discount->min_order_amount / 100, 0, '.', ' ');
            throw ValidationException::withMessages([
                'discount_code' => "Промокод действует от {$minRub} ₽",
            ]);
        }

        if ($discount->usage_limit !== null && $discount->usage_count >= $discount->usage_limit) {
            throw ValidationException::withMessages([
                'discount_code' => 'Промокод исчерпал лимит использований',
            ]);
        }

        if ($customerPhone && $discount->per_user_limit > 0) {
            $used = DiscountUse::where('discount_id', $discount->id)
                ->where('customer_phone', $customerPhone)
                ->count();

            if ($used >= $discount->per_user_limit) {
                throw ValidationException::withMessages([
                    'discount_code' => 'Вы уже использовали этот промокод',
                ]);
            }
        }
    }
}
