<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Master extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'masters';
    const UPDATED_AT = null;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'specialization',
        'avatar_url',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
    ];

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function services()
    {
        return $this->belongsToMany(Product::class, 'master_services', 'master_id', 'product_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Masters with no services assigned can perform any service (backward compat).
     * Masters with services assigned can only perform their listed services.
     */
    public function scopeCanPerform($query, string $productId)
    {
        return $query->where(function ($q) use ($productId) {
            $q->whereDoesntHave('services')
              ->orWhereHas('services', fn ($s) => $s->where('id', $productId));
        });
    }

    public function isAvailableAt($startTime, $endTime, ?string $excludeBookingId = null): bool
    {
        // Каноническое условие пересечения интервалов (строгие неравенства):
        // существующая запись конфликтует, если: existing.start < new.end И existing.end > new.start
        // Back-to-back (10:00-11:00 и 11:00-12:00) НЕ являются конфликтом.
        $query = $this->bookings()
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime);

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        return !$query->exists();
    }
}
