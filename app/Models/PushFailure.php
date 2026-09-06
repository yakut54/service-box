<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Лог несработавших push — публичная схема, изоляция по shop_id (точная калька
 * MailFailure). Нужен, чтобы «push молча не работает» не тянулось месяцами, как
 * это было с почтой. Запись создаётся один раз, без updated_at; created_at
 * проставляет БД.
 */
class PushFailure extends Model
{
    use HasUuids;

    protected $table = 'push_failures';

    public $timestamps = false;

    protected $fillable = [
        'shop_id',
        'customer_id',
        'entity_type',
        'entity_id',
        'error_message',
        'token_invalidated',
    ];

    protected $casts = [
        'token_invalidated' => 'boolean',
        'created_at'        => 'datetime',
    ];

    public function scopeForShop($query, string $shopId)
    {
        return $query->where('shop_id', $shopId);
    }
}
