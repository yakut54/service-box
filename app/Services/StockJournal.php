<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use Illuminate\Http\Request;

/**
 * Журнал РУЧНЫХ правок остатка — владелец/администратор поменял остаток в
 * карточке товара (см. ProductController::store/update). Не пишет сюда:
 * PhysicalStockService (списание/возврат при заказах) и OrderReweighService
 * (списание при взвешивании) — это следствие заказа, не правка человека.
 * Сборщик/мастер сюда не попадают вообще — у них нет доступа к товарам.
 */
class StockJournal
{
    /**
     * @param array{sale_mode:string, simple:int, weight:int, variants:array<string,int>} $oldStock
     *        снимок ДО ProductController::updateProductDetails()
     */
    public static function recordProductUpdate(Product $product, array $oldStock, Request $request): void
    {
        self::persist($product, self::diffRows($product, $oldStock), $request, null);
    }

    /** Начальный остаток при создании товара — «0 → N», фиксированная причина. */
    public static function recordProductCreate(Product $product, Request $request): void
    {
        self::persist($product, self::diffRows($product, null), $request, 'Товар создан');
    }

    private static function persist(Product $product, array $rows, Request $request, ?string $defaultReason): void
    {
        $staffRole = $request->attributes->get('staff_role');
        if (empty($rows) || !in_array($staffRole, ['owner', 'admin'], true)) {
            return;
        }

        $reason = $defaultReason ?? (trim((string) $request->input('stock_reason', '')) ?: null);
        $user = $request->user();

        foreach ($rows as &$row) {
            $row['actor_user_id'] = $user->id;
            $row['actor_name']    = $user->name;
            $row['actor_role']    = $staffRole;
            $row['reason']        = $reason;
        }

        StockAdjustment::insert($rows);
    }

    /**
     * @param array{sale_mode:string, simple:int, weight:int, variants:array<string,int>}|null $oldStock
     *        null — создание товара, сравнение безусловно с нулём.
     * @return list<array<string,mixed>>
     */
    private static function diffRows(Product $product, ?array $oldStock): array
    {
        $rows = [];
        $isNew = $oldStock === null;

        // Варианты существуют только у штучного товара (см.
        // ProductController::syncOptionsAndVariants) — остаток тогда ведётся
        // per-вариант, а не в products_physical.
        if ($product->options()->exists()) {
            foreach ($product->variants()->get(['id', 'stock_quantity']) as $v) {
                $old = $isNew ? 0 : ($oldStock['variants'][$v->id] ?? null);
                if ($old !== null && $old !== $v->stock_quantity) {
                    $rows[] = self::row($product, $v, 'pcs', $old, $v->stock_quantity);
                }
            }
            return $rows;
        }

        $saleMode = $product->physical()->value('sale_mode') ?? 'piece';

        // Сравнивать значение между режимами бессмысленно — поле меняется
        // не потому, что кто-то поправил остаток, а потому что поменялся
        // режим продажи (см. ProductController::normalizePhysical).
        if (!$isNew && $oldStock['sale_mode'] !== $saleMode) {
            return $rows;
        }

        [$unit, $new] = $saleMode === 'piece'
            ? ['pcs', $product->physical()->value('stock_quantity') ?? 0]
            : ['g',   $product->physical()->value('stock_weight_grams') ?? 0];
        $old = $isNew ? 0 : ($saleMode === 'piece' ? $oldStock['simple'] : $oldStock['weight']);

        if ($old !== $new) {
            $rows[] = self::row($product, null, $unit, $old, $new);
        }

        return $rows;
    }

    private static function row(Product $product, ?ProductVariant $variant, string $unit, int $old, int $new): array
    {
        return [
            'product_id'    => $product->id,
            'variant_id'    => $variant?->id,
            'product_name'  => $product->name,
            'variant_label' => $variant?->shortLabel(),
            'unit'          => $unit,
            'old_value'     => $old,
            'new_value'     => $new,
        ];
    }
}
