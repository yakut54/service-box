<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductService extends Model
{
    use HasUuids;

    protected $table = 'products_service';
    public $timestamps = false;
    protected $primaryKey = 'product_id';

    protected $fillable = [
        'product_id',
        'duration_minutes',
        'max_concurrent',
        'requires_booking',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'max_concurrent' => 'integer',
        'requires_booking' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
