<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'customers';

    const UPDATED_AT = null;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'notes',
        'avatar_url',
        'total_orders',
        'total_spent',
        'last_order_at',
        'fcm_token',
    ];

    protected $casts = [
        'total_orders' => 'integer',
        'total_spent' => 'integer',
        'created_at' => 'datetime',
        'last_order_at' => 'datetime',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function addresses()
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function chatThread()
    {
        return $this->hasOne(ChatThread::class);
    }

    public function getTotalSpentRublesAttribute(): float
    {
        return $this->total_spent / 100;
    }

    /**
     * Normalize phone to +7XXXXXXXXXX format.
     * Strips all non-digits and prepends '+'.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^\d]/', '', $phone);
        return '+' . $digits;
    }

    public static function findOrCreateByPhone(string $phone, array $data = []): self
    {
        $phone = self::normalizePhone($phone);

        $customer = self::where('phone', $phone)->first();

        if (!$customer) {
            $customer = self::create(array_merge($data, ['phone' => $phone]));
        } else if (!empty($data)) {
            if (isset($data['name'])) {
                $customer->name = $data['name'];
            }
            if (isset($data['email'])) {
                $customer->email = $data['email'];
            }
            $customer->save();
        }

        return $customer;
    }

    public function updateStats(): void
    {
        $this->total_orders = $this->orders()->count();
        $this->total_spent = $this->orders()->where('status', '!=', 'cancelled')->sum('total_price');
        $this->last_order_at = $this->orders()->latest('created_at')->first()?->created_at;
        $this->save();
    }
}
