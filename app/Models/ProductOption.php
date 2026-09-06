<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Ось вариативности товара («Размер», «Цвет») — до 3 на товар. Каталог
 * значений для селектора; сами комбинации — в ProductVariant.
 */
class ProductOption extends Model
{
    use HasUuids;

    protected $table = 'product_options';
    public $timestamps = false;

    protected $fillable = ['product_id', 'name', 'position'];
    protected $casts = ['position' => 'integer'];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function values()
    {
        return $this->hasMany(ProductOptionValue::class, 'option_id')->orderBy('position');
    }
}
