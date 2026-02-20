<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasUuids;

    const UPDATED_AT = null; // отзывы не редактируются — только публикуются/скрываются

    protected $table = 'reviews';

    protected $fillable = [
        'product_id',
        'customer_id',
        'order_id',
        'customer_name',
        'rating',
        'text',
        'is_published',
    ];

    protected $casts = [
        'rating'       => 'integer',
        'is_published' => 'boolean',
        'created_at'   => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }
}
