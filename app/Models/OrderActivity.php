<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrderActivity extends Model
{
    use HasUuids;

    protected $table = 'order_activity';

    const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'action',
        'from_status',
        'to_status',
        'note',
        'actor_user_id',
        'actor_name',
        'actor_role',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
