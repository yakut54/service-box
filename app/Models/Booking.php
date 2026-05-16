<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'bookings';

    protected $fillable = [
        'service_id',
        'customer_id',
        'master_id',
        'start_time',
        'end_time',
        'status',
        'payment_id',
        'payment_url',
        'paid_at',
        'customer_name',
        'customer_phone',
        'customer_email',
        'notes',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time'   => 'datetime',
        'paid_at'    => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function service()
    {
        return $this->belongsTo(Product::class, 'service_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function master()
    {
        return $this->belongsTo(Master::class);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['cancelled', 'no_show']);
    }

    public function scopeOnDate($query, string $date)
    {
        return $query->whereDate('start_time', $date);
    }

    public function confirm(): void
    {
        $this->update(['status' => 'confirmed']);
    }

    public function complete(): void
    {
        $this->update(['status' => 'completed']);
    }

    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
    }

    public function markAsPaid(string $paymentId): void
    {
        $this->update([
            'payment_id' => $paymentId,
            'paid_at'    => now(),
        ]);
    }
}
