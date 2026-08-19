<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    use HasUuids;

    protected $table = 'customer_addresses';

    protected $fillable = [
        'customer_id',
        'label',
        'city',
        'street',
        'building',
        'apartment',
        'postal_code',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
