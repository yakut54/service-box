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
        ];
    }

    public function shop()
    {
        return $this->hasOne(Shop::class);
    }

    public function staffShops()
    {
        return $this->hasMany(ShopStaff::class)->whereNotNull('accepted_at');
    }
}
