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
     * Самый старый магазин пользователя. Для одиночного шопера это и есть
     * «его магазин» — но у владельца сети (is_chain_owner) магазинов
     * несколько, и это НЕ «его магазин», а произвольная первая запись.
     * В таких случаях использовать shops() и App\Support\ShopAccess.
     */
    public function shop()
    {
        return $this->hasOne(Shop::class)->oldestOfMany();
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
