<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Review;

class Product extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'products';

    protected $fillable = [
        'type',
        'name',
        'description',
        'price',
        'compare_price',
        'currency',
        'image_url',
        'is_active',
        'category_id',
        'size_chart_id',
        'sort_order',
    ];

    protected $casts = [
        'price'         => 'integer',
        'compare_price' => 'integer',
        'is_active'     => 'boolean',
        'sort_order'    => 'integer',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function sizeChart()
    {
        return $this->belongsTo(SizeChart::class, 'size_chart_id');
    }

    public function physical()
    {
        return $this->hasOne(ProductPhysical::class, 'product_id');
    }

    public function digital()
    {
        return $this->hasOne(ProductDigital::class, 'product_id');
    }

    public function service()
    {
        return $this->hasOne(ProductService::class, 'product_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'product_id');
    }

    /**
     * Произвольные характеристики «label: value» (см. ProductAttribute).
     * Сериализуется в JSON как product_attributes.
     */
    public function productAttributes()
    {
        return $this->hasMany(ProductAttribute::class, 'product_id')->orderBy('sort_order');
    }

    /** Оси вариативности («Размер», «Цвет») с их значениями. */
    public function options()
    {
        return $this->hasMany(ProductOption::class, 'product_id')->orderBy('position');
    }

    /** Заведённые комбинации со своим SKU/ценой/остатком/фото. */
    public function variants()
    {
        return $this->hasMany(ProductVariant::class, 'product_id')->orderBy('position');
    }

    public function hasVariants(): bool
    {
        return $this->options()->exists();
    }

    public function stockSubscriptions()
    {
        return $this->hasMany(StockSubscription::class, 'product_id');
    }

    /**
     * Доп. фото галереи (не включает обложку — см. image_url).
     */
    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function getPriceRublesAttribute(): float
    {
        return $this->price / 100;
    }

    public function loadDetails(): self
    {
        return $this->load([
            'productAttributes',
            'sizeChart',
            'options.values',
            'variants',
            'physical' => function ($query) {
                return $this->type === 'physical' ? $query : $query->whereRaw('false');
            },
            'digital' => function ($query) {
                return $this->type === 'digital' ? $query : $query->whereRaw('false');
            },
            'service' => function ($query) {
                return $this->type === 'service' ? $query : $query->whereRaw('false');
            },
        ]);
    }
}
