<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Единая точка списания/возврата складского остатка физических товаров —
 * раньше проверка+decrement были продублированы в OrderController и
 * Api\WriteController, а increment при отмене/удалении — ещё в трёх местах
 * (и без учёта sale_mode вообще, из-за чего у весовых товаров
 * stock_quantity бессмысленно рос с каждой отменой).
 */
class PhysicalStockService
{
    /**
     * Списывает склад одной позиции заказа. Кидает 409 (abort), если не
     * хватает и allow_backorder не спасает. Вызывающий код должен сперва
     * получить $product с lockForUpdate() — эта функция сама блокировок не
     * ставит.
     *
     * Для weight_variable — ничего не делает: точное количество известно
     * только после взвешивания сборщиком, списание происходит там же
     * (см. OrderReweighService::submitActualWeight), не при заказе.
     *
     * $variant задан → остаток ведёт вариант (одежда/обувь), не products_physical.
     */
    public static function reserve(Product $product, int $quantity, ?int $weightGrams, ?ProductVariant $variant = null): void
    {
        if ($variant !== null) {
            if ($variant->stock_quantity < $quantity && !$variant->allow_backorder) {
                $combo = $variant->shortLabel();
                abort(409, "«{$product->name}»" . ($combo ? " ({$combo})" : '') . ' закончился на складе');
            }
            $variant->decrement('stock_quantity', $quantity);
            return;
        }

        $physical = $product->physical;
        if (!$physical) {
            return;
        }

        $saleMode = $physical->sale_mode ?? 'piece';

        if ($saleMode === 'piece') {
            if ($physical->stock_quantity < $quantity && !$physical->allow_backorder) {
                abort(409, "Товар «{$product->name}» закончился на складе");
            }
            $physical->decrement('stock_quantity', $quantity);
            return;
        }

        if ($saleMode === 'weight_fixed') {
            $grams = $weightGrams ?? 0;
            if ($physical->stock_weight_grams < $grams && !$physical->allow_backorder) {
                $availableKg = number_format($physical->stock_weight_grams / 1000, 1);
                abort(409, "Товар «{$product->name}» — на складе осталось {$availableKg} кг");
            }
            $physical->decrement('stock_weight_grams', $grams);
        }
    }

    /**
     * Возврат на склад — при отмене заказа или его удалении.
     */
    public static function release(OrderItem $item): void
    {
        $product = $item->product;
        if (!$product || $product->type !== 'physical') {
            return;
        }

        // Вариантная позиция — вернуть остаток именно варианту.
        if ($item->variant_id) {
            $variant = ProductVariant::find($item->variant_id);
            $variant?->increment('stock_quantity', $item->quantity);
            return;
        }

        $physical = $product->physical;
        if (!$physical) {
            return;
        }

        $saleMode = $physical->sale_mode ?? 'piece';

        if ($saleMode === 'piece') {
            $physical->increment('stock_quantity', $item->quantity);
        } elseif ($saleMode === 'weight_fixed' && $item->weight_grams) {
            $physical->increment('stock_weight_grams', $item->weight_grams);
        } elseif ($saleMode === 'weight_variable' && $item->actual_weight_grams) {
            // Списано было не при заказе, а при взвешивании (см. reserve()
            // выше) — возвращать нечего, если сборщик ещё не подтвердил вес.
            $physical->increment('stock_weight_grams', $item->actual_weight_grams);
        }
    }
}
