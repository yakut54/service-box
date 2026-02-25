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
     * @param  array<array{product_id: string, category_id: string|null, quantity: int}>|null $cartItems
     * @throws ValidationException
     * @return array{discount: Discount, amount: int}
     */
    public function validate(string $code, int $cartAmount, ?string $customerPhone = null, ?array $cartItems = null): array
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

        $this->assertUsable($discount, $cartAmount, $customerPhone, $cartItems);

        return [
            'discount' => $discount,
            'amount'   => $this->calculate($discount, $cartAmount, $cartItems),
        ];
    }

    /**
     * Find the best auto-apply (codeless) discount for this cart.
     *
     * @param  array<array{product_id: string, category_id: string|null, quantity: int}>|null $cartItems
     */
    public function findAutoApply(int $cartAmount, ?string $customerPhone = null, ?array $cartItems = null): ?Discount
    {
        $discounts = Discount::active()
            ->whereNull('code')
            ->where('min_order_amount', '<=', $cartAmount)
            ->get();

        $best       = null;
        $bestAmount = 0;

        foreach ($discounts as $discount) {
            try {
                $this->assertUsable($discount, $cartAmount, $customerPhone, $cartItems);
            } catch (ValidationException) {
                continue;
            }

            $amount = $this->calculate($discount, $cartAmount, $cartItems);
            if ($amount > $bestAmount) {
                $bestAmount = $amount;
                $best       = $discount;
            }
        }

        return $best;
    }

    /**
     * Calculate discount amount in kopecks for a given cart total.
     *
     * For product/category scoped discounts, applies only to matching items.
     *
     * @param  array<array{product_id: string, category_id: string|null, quantity: int}>|null $cartItems
     */
    public function calculate(Discount $discount, int $cartAmount, ?array $cartItems = null): int
    {
        // For scoped discounts, recalculate base amount from matching items only
        $base = $cartAmount;
        if ($cartItems && $discount->scope_value) {
            $base = $this->scopedCartAmount($discount, $cartItems) ?: $cartAmount;
        }

        $amount = $discount->type === 'percent'
            ? (int) round($base * $discount->value / 100)
            : $discount->value;

        // Apply cap
        if ($discount->max_discount_amount !== null) {
            $amount = min($amount, $discount->max_discount_amount);
        }

        // Cannot exceed cart amount (no negative totals)
        return min($amount, $cartAmount);
    }

    /**
     * Sum prices of cart items that match the discount scope.
     * Returns 0 if no items match (caller decides how to handle).
     *
     * @param  array<array{product_id: string, category_id: string|null, quantity: int}> $cartItems
     */
    private function scopedCartAmount(Discount $discount, array $cartItems): int
    {
        if ($discount->scope === 'product') {
            return collect($cartItems)
                ->where('product_id', $discount->scope_value)
                ->sum(fn ($i) => ($i['price'] ?? 0) * $i['quantity']);
        }

        if ($discount->scope === 'category') {
            return collect($cartItems)
                ->where('category_id', $discount->scope_value)
                ->sum(fn ($i) => ($i['price'] ?? 0) * $i['quantity']);
        }

        return 0;
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
     * @param  array<array{product_id: string, category_id: string|null, quantity: int}>|null $cartItems
     * @throws ValidationException
     */
    private function assertUsable(Discount $discount, int $cartAmount, ?string $customerPhone, ?array $cartItems = null): void
    {
        // Scope check: product/category scoped discounts require matching items in cart
        if ($cartItems && $discount->scope_value) {
            if ($discount->scope === 'product') {
                $match = collect($cartItems)->contains('product_id', $discount->scope_value);
                if (!$match) {
                    throw ValidationException::withMessages([
                        'discount_code' => 'Промокод не применим к товарам в вашей корзине',
                    ]);
                }
            }

            if ($discount->scope === 'category') {
                $match = collect($cartItems)->contains('category_id', $discount->scope_value);
                if (!$match) {
                    throw ValidationException::withMessages([
                        'discount_code' => 'Промокод не применим к товарам в вашей корзине',
                    ]);
                }
            }
        }

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
