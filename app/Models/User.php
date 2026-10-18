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

    /**
     * "Онлайн" — живой Sanctum-токен прямо сейчас, не last_login_at (не
     * отражает выход) и не WS-presence (нужна открытая вкладка прямо
     * сейчас, не переживает обрыв соединения) — тот же источник истины,
     * что уже используется для "Активен" в разделе Команда
     * (StaffController::index) и для карточки заказа
     * (OrderController::show). Статический метод — не требует
     * предзагрузки модели, нужен только id.
     */
    public static function isOnline(string $userId): bool
    {
        return \Illuminate\Support\Facades\DB::table('personal_access_tokens')
            ->where('tokenable_type', self::class)
            ->where('tokenable_id', $userId)
            ->exists();
    }
}
