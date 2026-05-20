<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ShopStaff extends Model
{
    use HasUuids;

    protected $table = 'shop_staff';

    protected $fillable = [
        'shop_id',
        'user_id',
        'role',
        'invite_email',
        'invite_token',
        'accepted_at',
        'invite_expires_at',
    ];

    protected $casts = [
        'accepted_at'       => 'datetime',
        'invite_expires_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }
}
