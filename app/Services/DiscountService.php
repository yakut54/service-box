<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\DiscountUse;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;
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
            ->lockForUpdate()
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
            ->orderByDesc('priority')
            ->lockForUpdate()
            ->get();

        $best         = null;
        $bestAmount   = 0;
        $bestPriority = null;

        foreach ($discounts as $discount) {
            // Once we have a winner at a given priority tier, stop checking lower tiers
            if ($bestPriority !== null && $discount->priority < $bestPriority) {
                break;
            }

            try {
                $this->assertUsable($discount, $cartAmount, $customerPhone, $cartItems);
            } catch (ValidationException) {
                continue;
            }

            $amount = $this->calculate($discount, $cartAmount, $cartItems);
            if ($amount > $bestAmount) {
                $bestAmount   = $amount;
                $best         = $discount;
                $bestPriority = $discount->priority;
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
     * Лучшая auto-apply скидка на конкретный товар — для бейджа и зачёркнутой
     * цены в каталоге (см. ProductController). Не одно и то же с
     * assertUsable(): здесь нет контекста конкретной корзины/клиента, поэтому
     * min_order_amount и per_user_limit сознательно не проверяются (это лимиты
     * уровня корзины/клиента, а бейдж — это «на этот товар вообще есть акция»,
     * не гарантия применимости прямо сейчас). usage_limit проверяется — если
     * акция исчерпана полностью, бейджу нечего обещать.
     *
     * Отдаём percent И amount из одного и того же $bestAmount, а не только
     * процент — если бы фронтенд сам пересчитывал «старую цену» обратным
     * счётом от округлённого процента, сумма могла бы разъехаться на
     * копейку с реальной (тот же класс бага, что чинили в М10/М11).
     *
     * @param  Collection<int, Discount>  $activeAutoDiscounts  см. Discount::active()->whereNull('code')
     * @return array{percent: int, amount: int, ends_at: string|null}|null
     */
    public function bestBadge(Product $product, Collection $activeAutoDiscounts): ?array
    {
        $cartItems = [[
            'product_id'  => $product->id,
            'category_id' => $product->category_id,
            'quantity'    => 1,
            'price'       => $product->price,
        ]];

        $best         = null;
        $bestAmount   = 0;
        $bestPriority = null;

        foreach ($activeAutoDiscounts as $discount) {
            if ($bestPriority !== null && $discount->priority < $bestPriority) {
                break;
            }

            if ($discount->scope_value) {
                if ($discount->scope === 'product' && $discount->scope_value !== $product->id) continue;
                if ($discount->scope === 'category' && $discount->scope_value !== $product->category_id) continue;
            }

            if ($discount->usage_limit !== null && $discount->usage_count >= $discount->usage_limit) {
                continue;
            }

            $amount = $this->calculate($discount, $product->price, $cartItems);
            if ($amount > $bestAmount) {
                $bestAmount   = $amount;
                $best         = $discount;
                $bestPriority = $discount->priority;
            }
        }

        if (!$best || $bestAmount <= 0) return null;

        return [
            'percent' => (int) round($bestAmount / $product->price * 100),
            'amount'  => $bestAmount,
            'ends_at' => $best->ends_at?->toIso8601String(),
        ];
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
            $minRub = number_format($discount->min_order_amount, 0, '.', ' ');
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
