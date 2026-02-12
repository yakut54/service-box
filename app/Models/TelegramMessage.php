<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TelegramMessage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'shop_id',
        'telegram_message_id',
        'telegram_chat_id',
        'type',
        'entity_id',
        'entity_type',
        'message_text',
        'inline_keyboard',
        'status',
        'error_message',
        'user_action',
        'user_action_at',
    ];

    protected $casts = [
        'telegram_message_id' => 'integer',
        'telegram_chat_id' => 'integer',
        'inline_keyboard' => 'array',
        'user_action_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function isSent(): bool
    {
        return $this->status === 'sent' || $this->status === 'delivered';
    }

    public function hasUserAction(): bool
    {
        return !is_null($this->user_action);
    }

    public function scopeForShop($query, string $shopId)
    {
        return $query->where('shop_id', $shopId);
    }

    public function scopeSent($query)
    {
        return $query->whereIn('status', ['sent', 'delivered']);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeForEntity($query, string $entityType, string $entityId)
    {
        return $query->where('entity_type', $entityType)
                     ->where('entity_id', $entityId);
    }
}
