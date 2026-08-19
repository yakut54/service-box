<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Долгоживущий токен входа для мобильного приложения — отдельно от
 * 30-минутного OTP-токена веб-виджета (см. WidgetPhoneVerificationController
 * и VerifyPhoneToken). Байер проходит SMS-проверку один раз и остаётся
 * в системе несколько месяцев, пока сам не выйдет.
 */
class CustomerSession extends Model
{
    use HasUuids;

    protected $table = 'customer_sessions';

    const UPDATED_AT = null;

    protected $fillable = [
        'customer_id',
        'token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public static function issueFor(Customer $customer): self
    {
        return self::create([
            'customer_id' => $customer->id,
            'token' => bin2hex(random_bytes(32)),
            'expires_at' => now()->addDays(60),
        ]);
    }
}
