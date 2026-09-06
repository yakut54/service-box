<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Подписка покупателя на «сообщить о поступлении» товара (или конкретного
 * варианта). Удаляется после уведомления (см. Jobs\NotifyBackInStock).
 */
class StockSubscription extends Model
{
    use HasUuids;

    protected $table = 'stock_subscriptions';
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'variant_id',
        'customer_id',
        'notified_at',
        'created_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
        'created_at'  => 'datetime',
    ];
}
