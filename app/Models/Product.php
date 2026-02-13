<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'products';

    protected $fillable = [
        'type',
        'name',
        'description',
        'price',
        'currency',
        'image_url',
        'is_active',
        'category',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function physical()
    {
        return $this->hasOne(ProductPhysical::class, 'product_id');
    }

    public function digital()
    {
        return $this->hasOne(ProductDigital::class, 'product_id');
    }

    public function service()
    {
        return $this->hasOne(ProductService::class, 'product_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function getPriceRublesAttribute(): float
    {
        return $this->price / 100;
    }

    public function loadDetails(): self
    {
        return $this->load([
            'physical' => function ($query) {
                return $this->type === 'physical' ? $query : $query->whereRaw('false');
            },
            'digital' => function ($query) {
                return $this->type === 'digital' ? $query : $query->whereRaw('false');
            },
            'service' => function ($query) {
                return $this->type === 'service' ? $query : $query->whereRaw('false');
            },
        ]);
    }
}
