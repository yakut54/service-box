<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ChatThread extends Model
{
    use HasUuids;

    protected $table = 'chat_threads';

    protected $fillable = [
        'customer_id',
        'last_message_at',
        'last_message_preview',
        'unread_by_shop',
        'unread_by_customer',
        'shop_last_read_at',
        'customer_last_read_at',
        'customer_last_seen_at',
        'is_blocked_by_shop',
    ];

    protected $casts = [
        'unread_by_shop'        => 'integer',
        'unread_by_customer'    => 'integer',
        'is_blocked_by_shop'    => 'boolean',
        'last_message_at'       => 'datetime',
        'shop_last_read_at'     => 'datetime',
        'customer_last_read_at' => 'datetime',
        'customer_last_seen_at' => 'datetime',
        'created_at'            => 'datetime',
        'updated_at'            => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'thread_id');
    }

    /**
     * Пересчитывает last_message_at/last_message_preview из фактического
     * последнего сообщения — нужно после любого удаления (и тихого админского,
     * и «надгробия» байера), которое могло убрать как раз то сообщение, что
     * сейчас показано в списке диалогов. Общий код для
     * Admin\ChatController::deleteMessage и ChatController::destroy — раньше
     * был скопирован в оба места по отдельности.
     */
    public function refreshLastMessagePreview(): void
    {
        $latest = $this->messages()->orderByDesc('created_at')->first();

        $this->update([
            'last_message_at'      => $latest?->created_at,
            'last_message_preview' => $latest
                ? ($latest->deleted_at ? 'Сообщение удалено' : mb_substr($latest->body ?? '📷 Фото', 0, 80))
                : null,
        ]);
    }
}
