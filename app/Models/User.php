<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuids;

    protected $fillable = [
        'name',
        'email',
        'password',
        'terms_accepted_at',
        'terms_accepted_ip',
        'is_superadmin',
        'is_chain_owner',
        'avatar_url',
        'phone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'terms_accepted_at' => 'datetime',
            'is_superadmin'     => 'boolean',
            'is_chain_owner'    => 'boolean',
        ];
    }

    /**
     * ВНИМАНИЕ: при нескольких магазинах у одного user_id (владелец сети,
     * is_chain_owner) отдаёт произвольную строку — Eloquent hasOne не
     * гарантирует, какую именно. (Детерминированный вариант через
     * hasOne(...)->oldestOfMany() тут не работает: у ofMany есть встроенный
     * tie-break через MIN/MAX(id), а id — uuid, для которого в Postgres нет
     * агрегатных MIN/MAX.) Для владельца сети использовать shops() и
     * App\Support\ShopAccess, а не это свойство напрямую.
     */
    public function shop()
    {
        return $this->hasOne(Shop::class);
    }

    public function shops()
    {
        return $this->hasMany(Shop::class);
    }

    public function staffShops()
    {
        return $this->hasMany(ShopStaff::class)->whereNotNull('accepted_at');
    }
}
