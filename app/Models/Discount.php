<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasUuids;

    protected $table = 'discounts';

    protected $fillable = [
        'name',
        'type',
        'value',
        'code',
        'scope',
        'scope_value',
        'min_order_amount',
        'max_discount_amount',
        'usage_limit',
        'usage_count',
        'per_user_limit',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'value'               => 'integer',
        'min_order_amount'    => 'integer',
        'max_discount_amount' => 'integer',
        'usage_limit'         => 'integer',
        'usage_count'         => 'integer',
        'per_user_limit'      => 'integer',
        'is_active'           => 'boolean',
        'starts_at'           => 'datetime',
        'ends_at'             => 'datetime',
    ];

    public function uses()
    {
        return $this->hasMany(DiscountUse::class);
    }

    public function scopeActive($query)
    {
        return $query
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
