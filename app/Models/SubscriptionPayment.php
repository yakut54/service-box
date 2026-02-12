<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'shop_id',
        'payment_id',
        'plan',
        'amount',
        'currency',
        'status',
        'period_start',
        'period_end',
        'metadata',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'metadata' => 'array',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function getPeriodDays(): int
    {
        if (!$this->period_start || !$this->period_end) {
            return 0;
        }

        return $this->period_start->diffInDays($this->period_end);
    }

    public function scopeSucceeded($query)
    {
        return $query->where('status', 'succeeded');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForShop($query, string $shopId)
    {
        return $query->where('shop_id', $shopId);
    }
}
