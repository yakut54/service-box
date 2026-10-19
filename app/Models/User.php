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
     * Живой разбор 2026-10-19: "Егорка не онлайн, а показывает онлайн" —
     * Sanctum-токен сам по себе НИКОГДА не истекает (config/sanctum.php:
     * 'expiration' => null) и не удаляется, пока явно не нажали "Выйти";
     * закрыл вкладку/сел телефон — токен как был живым в базе, так и
     * остаётся. "Токен существует" ≠ "сидит в приложении прямо сейчас".
     *
     * Исправлено на last_used_at — поле, которое Sanctum САМ обновляет на
     * каждом аутентифицированном запросе, без единой строчки своего кода
     * — плюс то же окно (ONLINE_WINDOW_MINUTES), что уже используется в
     * проекте для throttle last_login_at/обновления счётчиков. Не
     * WS-presence (нужна открытая вкладка прямо сейчас, не переживает
     * обрыв соединения) и не голый last_login_at (не отражает выход) —
     * компромисс между ними, тот же источник истины, что используется
     * для "Активен" в разделе Команда (StaffController::index, свой
     * батч-запрос по тем же правилам) и для карточки заказа
     * (OrderController::show).
     *
     * COALESCE(last_used_at, created_at) — у только что созданного токена
     * (человек буквально только что вошёл) last_used_at ещё null: Sanctum
     * проставляет его не при создании, а при первом же запросе ЭТИМ
     * токеном. Без COALESCE свежевошедший на секунду оказался бы "не в
     * сети".
     */
    public const ONLINE_WINDOW_MINUTES = 5;

    public static function isOnline(string $userId): bool
    {
        return \Illuminate\Support\Facades\DB::table('personal_access_tokens')
            ->where('tokenable_type', self::class)
            ->where('tokenable_id', $userId)
            ->whereRaw('COALESCE(last_used_at, created_at) >= ?', [now()->subMinutes(self::ONLINE_WINDOW_MINUTES)])
            ->exists();
    }
}
