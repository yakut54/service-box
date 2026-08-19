<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Доп. фото товара для галереи на карточке (см. М1 в PLAN.md).
 * Обложка (products.image_url) сюда не входит — это только доп. снимки.
 */
class ProductImage extends Model
{
    use HasUuids;

    protected $table = 'product_images';

    const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'url',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'created_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
