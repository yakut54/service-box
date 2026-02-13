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
        'payment_id',
        'payment_url',
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_address',
        'notes',
        'paid_at',
    ];

    protected $casts = [
        'total_price' => 'integer',
        'shipping_address' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
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
