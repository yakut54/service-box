<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Значение оси вариативности («S», «Чёрный»). Позиционно соответствует
 * ProductVariant.option_values (текст, не FK).
 */
class ProductOptionValue extends Model
{
    use HasUuids;

    protected $table = 'product_option_values';
    public $timestamps = false;

    protected $fillable = ['option_id', 'value', 'position'];
    protected $casts = ['position' => 'integer'];

    public function option()
    {
        return $this->belongsTo(ProductOption::class, 'option_id');
    }
}
