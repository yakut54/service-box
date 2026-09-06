<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Реально заведённая комбинация опций товара — своя цена/остаток/SKU/фото.
 * option_values — массив значений строками, позиционно к ProductOption.position
 * (напр. ["M", "Чёрный"]). Цена null → наследует products.price.
 */
class ProductVariant extends Model
{
    use HasUuids;

    protected $table = 'product_variants';

    protected $fillable = [
        'product_id',
        'sku',
        'price',
        'stock_quantity',
        'allow_backorder',
        'image_url',
        'option_values',
        'is_active',
        'position',
    ];

    protected $casts = [
        'price'           => 'integer',
        'stock_quantity'  => 'integer',
        'allow_backorder' => 'boolean',
        'option_values'   => 'array',
        'is_active'       => 'boolean',
        'position'        => 'integer',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Цена варианта в копейках — своя, иначе цена товара. */
    public function effectivePrice(int $productPrice): int
    {
        return $this->price ?? $productPrice;
    }

    /** «S · Чёрный» — из значений, без имён опций (для короткой подписи). */
    public function shortLabel(): string
    {
        return implode(' · ', array_filter((array) $this->option_values, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * «Размер: M · Цвет: Чёрный» — снимок для order_items.variant_label.
     * @param array<int,string> $optionNames имена опций по позиции (0-based)
     */
    public function fullLabel(array $optionNames): string
    {
        $parts = [];
        foreach ((array) $this->option_values as $i => $val) {
            if ($val === null || $val === '') {
                continue;
            }
            $name = $optionNames[$i] ?? null;
            $parts[] = $name ? "{$name}: {$val}" : $val;
        }
        return implode(' · ', $parts);
    }

    public function inStock(): bool
    {
        return $this->is_active && ($this->stock_quantity > 0 || $this->allow_backorder);
    }
}
