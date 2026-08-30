<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Лог несработавших писем — публичная схема, изоляция по shop_id (см. TelegramMessage,
 * тот же паттерн). Запись создаётся один раз и больше не обновляется, поэтому без
 * updated_at — created_at проставляет сама БД (DEFAULT CURRENT_TIMESTAMP).
 */
class MailFailure extends Model
{
    use HasUuids;

    protected $table = 'mail_failures';

    public $timestamps = false;

    protected $fillable = [
        'shop_id',
        'entity_type',
        'entity_id',
        'recipient_type',
        'recipient_email',
        'error_message',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function scopeForShop($query, string $shopId)
    {
        return $query->where('shop_id', $shopId);
    }
}
