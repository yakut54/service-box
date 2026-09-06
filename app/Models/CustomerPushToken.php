<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Токен устройства покупателя для push. Тенантная таблица (рядом с customers,
 * customer_sessions). Заменяет одно поле customers.fcm_token — у покупателя может
 * быть несколько устройств. Уникальность по token; на FCM 404/UNREGISTERED строка
 * удаляется (см. FirebaseService), давно не обновлявшиеся чистит крон
 * push:prune-stale-tokens.
 */
class CustomerPushToken extends Model
{
    use HasUuids;

    protected $table = 'customer_push_tokens';

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'token',
        'platform',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
