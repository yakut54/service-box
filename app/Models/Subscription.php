<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'subscriptions';

    protected $fillable = [
        'shop_id',
        'plan',
        'amount_kopecks',
        'payment_id',
        'payment_provider',
        'paid_at',
        'expires_at',
    ];

    protected $casts = [
        'amount_kopecks' => 'integer',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function isActive(): bool
    {
        return $this->expires_at && $this->expires_at->isFuture();
    }

    public function getAmountRublesAttribute(): float
    {
        return $this->amount_kopecks / 100;
    }
}
