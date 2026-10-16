<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductChange extends Model
{
    use HasUuids;

    protected $table = 'product_changes';

    const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'variant_id',
        'product_name',
        'variant_label',
        'action',
        'field',
        'unit',
        'old_value',
        'new_value',
        'actor_user_id',
        'actor_name',
        'actor_role',
        'reason',
    ];

    protected $casts = [
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
