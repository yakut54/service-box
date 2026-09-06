<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'order_items';
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'variant_label',
        'quantity',
        'price',
        'product_name',
        'product_type',
        'weight_grams',
        'actual_weight_grams',
        'actual_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'integer',
        'weight_grams' => 'integer',
        'actual_weight_grams' => 'integer',
        'actual_price' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function getSubtotalAttribute(): int
    {
        return $this->price * $this->quantity;
    }

    public function getSubtotalRublesAttribute(): float
    {
        return $this->subtotal / 100;
    }
}
