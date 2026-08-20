<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ShopFeature extends Model
{
    use HasUuids;

    protected $table = 'shop_features';

    protected $fillable = [
        'shop_id',
        'feature_key',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
