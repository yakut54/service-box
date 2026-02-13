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
        'total_orders',
        'total_spent',
        'last_order_at',
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

    public function getTotalSpentRublesAttribute(): float
    {
        return $this->total_spent / 100;
    }

    public static function findOrCreateByPhone(string $phone, array $data = []): self
    {
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
