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
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function thread()
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
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
