<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductPhysical extends Model
{
    use HasUuids;

    protected $table = 'products_physical';
    public $timestamps = false;
    protected $primaryKey = 'product_id';

    protected $fillable = [
        'product_id',
        'sku',
        'stock_quantity',
        'allow_backorder',
        'weight_grams',
        'length_cm',
        'width_cm',
        'height_cm',
        'color',
        'brand',
        'material',
        'dimensions',
        'sale_mode',
        'weight_step_grams',
        'weight_min_grams',
        'weight_max_grams',
        'stock_weight_grams',
        'units_per_pack',
        'unit_label',
        'marking_code',
    ];

    protected $casts = [
        'stock_quantity' => 'integer',
        'allow_backorder' => 'boolean',
        'weight_grams' => 'integer',
        'length_cm' => 'float',
        'width_cm' => 'float',
        'height_cm' => 'float',
        'weight_step_grams' => 'integer',
        'weight_min_grams' => 'integer',
        'weight_max_grams' => 'integer',
        'stock_weight_grams' => 'integer',
        'units_per_pack' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
