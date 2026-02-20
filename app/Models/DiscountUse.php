<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DiscountUse extends Model
{
    use HasUuids;

    protected $table = 'discount_uses';

    // Only created_at, no updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'discount_id',
        'order_id',
        'customer_phone',
        'discount_amount',
    ];

    protected $casts = [
        'discount_amount' => 'integer',
        'created_at'      => 'datetime',
    ];

    public function discount()
    {
        return $this->belongsTo(Discount::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
