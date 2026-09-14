<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class Shop extends Model
{
    use HasFactory, HasUuids, HasApiTokens;

    protected $table = 'shops';

    protected $fillable = [
        'user_id',
        'name',
        'domain',
        'schema_name',
        'telegram_chat_id',
        'telegram_bot_connected',
        'max_chat_id',
        'max_bot_connected',
        'payment_provider',
        'yookassa_shop_id',
        'yookassa_secret_key',
        'robokassa_login',
        'robokassa_password1',
        'robokassa_password2',
        'widget_config',
        'legal_config',
        'delivery_settings',
        'work_start',
        'work_end',
        'slot_duration',
        'min_booking_notice',
        'prepayment_enabled',
        'prepayment_amount',
        'timezone',
        'hide_customer_phone',
        'chat_customer_delete_enabled',
        'reviews_last_seen_at',
        'mail_failures_last_seen_at',
        'customer_push_enabled',
    ];

    protected $casts = [
        'telegram_bot_connected' => 'boolean',
        'max_bot_connected'      => 'boolean',
        'chat_customer_delete_enabled' => 'boolean',
        'reviews_last_seen_at' => 'datetime',
        'mail_failures_last_seen_at' => 'datetime',
        'max_chat_id'            => 'integer',
        'widget_config'     => 'array',
        'legal_config'      => 'array',
        'delivery_settings' => 'array',
        'slot_duration'      => 'integer',
        'min_booking_notice'  => 'integer',
        'prepayment_enabled'    => 'boolean',
        'prepayment_amount'     => 'integer',
        'hide_customer_phone'   => 'boolean',
        'customer_push_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = [
        'schema_name',
        'yookassa_secret_key',
        'robokassa_password1',
        'robokassa_password2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($shop) {
            if (empty($shop->schema_name)) {
                $shop->schema_name = 'shop_' . strtolower(Str::random(12));
            }

            if (empty($shop->api_key)) {
                $shop->api_key = Str::uuid();
            }

            if (empty($shop->widget_config)) {
                $shop->widget_config = [
                    'primary_color' => '#6366f1',
                    'secondary_color' => '#f59e0b',
                    'font_family' => 'Inter, sans-serif',
                    'logo_url' => null,
                    'border_radius' => 8,
                    'show_search' => true,
                    'show_categories' => true,
                ];
            }
        });

        static::created(function ($shop) {
            \DB::statement('SELECT public.create_shop_schema(?)', [$shop->schema_name]);
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function features()
    {
        return $this->hasMany(ShopFeature::class);
    }

    /**
     * Entitlement для «Флота Шоперов» (booking/digital_goods и т.п.) — НЕ имеет
     * отношения к снесённым тарифам. Отсутствие строки = выключено. Физические
     * товары сюда не входят — это база, доступна всем без флага.
     */
    public function hasFeature(string $key): bool
    {
        return $this->features()->where('feature_key', $key)->where('enabled', true)->exists();
    }

    public function hasLegalDocs(): bool
    {
        $cfg = $this->legal_config ?? [];
        return !empty($cfg['public_offer_text']) || !empty($cfg['privacy_policy_text']);
    }
}
