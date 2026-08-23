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
        'is_blocked_by_shop',
    ];

    protected $casts = [
        'unread_by_shop'        => 'integer',
        'unread_by_customer'    => 'integer',
        'is_blocked_by_shop'    => 'boolean',
        'last_message_at'       => 'datetime',
        'shop_last_read_at'     => 'datetime',
        'customer_last_read_at' => 'datetime',
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
}
