<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasUuids;

    protected $table = 'stock_adjustments';

    const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'variant_id',
        'product_name',
        'variant_label',
        'unit',
        'old_value',
        'new_value',
        'actor_user_id',
        'actor_name',
        'actor_role',
        'reason',
    ];

    protected $casts = [
        'old_value'  => 'integer',
        'new_value'  => 'integer',
        'created_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
