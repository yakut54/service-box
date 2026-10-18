<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $table = 'chat_messages';

    protected $fillable = [
        'thread_id',
        'sender_type',
        'sender_staff_id',
        'client_message_id',
        'body',
        'image_url',
        'status',
        'reply_to_message_id',
        'reply_quote',
        'edited_at',
        'deleted_at',
        'hidden_from_customer_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'edited_at'  => 'datetime',
        'deleted_at' => 'datetime',
        'hidden_from_customer_at' => 'datetime',
    ];

    // Служебное поле — только для Widget\ChatController (какие свои
    // сообщения покупатель скрыл у себя второй ступенью удаления). Персоналу
    // в ответе не нужно, у него и так deleted_at с 1-й ступени (2026-10-18).
    protected $hidden = ['hidden_from_customer_at'];

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * Представление сообщения ДЛЯ ПОКУПАТЕЛЯ (не для персонала) — если он сам
     * удалил сообщение (deleted_at), текст/фото прячутся только в ЭТОМ
     * ответе, в БД они остаются навсегда: персонал должен видеть исходное
     * содержимое, даже если покупатель считает, что стёр его безвозвратно
     * (2026-10-18, модерация). Admin\ChatController этот метод не вызывает —
     * отдаёт toArray() как есть.
     */
    public function toCustomerArray(): array
    {
        $array = $this->toArray();
        if ($this->deleted_at) {
            $array['body'] = null;
            $array['image_url'] = null;
        }
        if ($this->relationLoaded('replyTo') && $this->replyTo) {
            $array['reply_to'] = $this->replyTo->toCustomerArray();
        }
        return $array;
    }

    public function thread()
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    public function replyTo()
    {
        return $this->belongsTo(ChatMessage::class, 'reply_to_message_id');
    }

    /**
     * Сотрудник живёт в public-схеме (см. ChatThread — тот же принцип, что у
     * masters.user_id -> public.users), поэтому явная связь через конкретное
     * подключение, а не обычный belongsTo внутри текущего search_path.
     */
    public function staff()
    {
        return $this->belongsTo(ShopStaff::class, 'sender_staff_id');
    }
}
