<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'orders';

    protected $fillable = [
        'customer_id',
        'status',
        'total_price',
        'discount_id',
        'discount_code',
        'discount_amount',
        'payment_id',
        'payment_url',
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_address',
        'delivery_method',
        'delivery_price',
        'notes',
        'paid_at',
        'consent_offer_accepted',
        'consent_privacy_accepted',
        'consent_accepted_at',
        'consent_ip',
        'consent_ua',
    ];

    protected $casts = [
        'total_price'              => 'integer',
        'discount_amount'          => 'integer',
        'delivery_price'           => 'integer',
        'shipping_address'         => 'array',
        'consent_offer_accepted'   => 'boolean',
        'consent_privacy_accepted' => 'boolean',
        'created_at'               => 'datetime',
        'updated_at'               => 'datetime',
        'paid_at'                  => 'datetime',
        'consent_accepted_at'      => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function discount()
    {
        return $this->belongsTo(\App\Models\Discount::class);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePaid($query)
    {
        return $query->whereNotNull('paid_at');
    }

    public function getTotalPriceRublesAttribute(): float
    {
        return $this->total_price / 100;
    }

    public function calculateTotal(): void
    {
        $this->total_price = $this->items()->sum(\DB::raw('price * quantity'));
        $this->save();
    }

    public function markAsPaid(string $paymentId): void
    {
        $this->update([
            'status' => 'paid',
            'payment_id' => $paymentId,
            'paid_at' => now(),
        ]);

        if ($this->customer) {
            $this->customer->updateStats();
        }
    }
}
