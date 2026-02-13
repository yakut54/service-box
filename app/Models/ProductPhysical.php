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
    ];

    protected $casts = [
        'stock_quantity' => 'integer',
        'allow_backorder' => 'boolean',
        'weight_grams' => 'integer',
        'length_cm' => 'float',
        'width_cm' => 'float',
        'height_cm' => 'float',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
