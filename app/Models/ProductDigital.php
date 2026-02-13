<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductDigital extends Model
{
    use HasUuids;

    protected $table = 'products_digital';
    public $timestamps = false;
    protected $primaryKey = 'product_id';

    protected $fillable = [
        'product_id',
        'delivery_type',
        'access_days',
        'download_url',
        'file_size_bytes',
    ];

    protected $casts = [
        'access_days' => 'integer',
        'file_size_bytes' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
