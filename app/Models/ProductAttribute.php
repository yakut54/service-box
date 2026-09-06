<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Произвольная характеристика товара — пара «label: value», которую шопер
 * добавляет сам в форме товара. Универсальный механизм вместо новой колонки
 * под каждую категорию (состав/КБЖУ для еды, тех-спеки для электроники и т.д.).
 * При сохранении товара перезаписываются целиком (см. ProductController::
 * syncAttributes) — своего updated_at не нужно.
 */
class ProductAttribute extends Model
{
    use HasUuids;

    protected $table = 'product_attributes';

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'label',
        'value',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
