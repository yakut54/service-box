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
        'payment_provider',
        'yookassa_shop_id',
        'yookassa_secret_key',
        'robokassa_login',
        'robokassa_password1',
        'robokassa_password2',
        'subscription_plan',
        'subscription_expires_at',
        'widget_config',
    ];

    protected $casts = [
        'telegram_bot_connected' => 'boolean',
        'subscription_expires_at' => 'datetime',
        'widget_config' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = [
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

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->where('expires_at', '>', now())
            ->latest('expires_at');
    }

    public function hasActiveSubscription(): bool
    {
        return $this->subscription_expires_at && $this->subscription_expires_at->isFuture();
    }

    public function subscriptionPayments()
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function isSubscriptionExpiringSoon(): bool
    {
        if (!$this->subscription_expires_at) {
            return false;
        }

        $daysLeft = now()->diffInDays($this->subscription_expires_at, false);
        return $daysLeft <= 7 && $daysLeft > 0;
    }

    public function getDaysUntilExpiration(): int
    {
        if (!$this->subscription_expires_at) {
            return 0;
        }

        return max(0, now()->diffInDays($this->subscription_expires_at, false));
    }

    public function getPlanLimits(): array
    {
        return match($this->subscription_plan) {
            'micro' => [
                'max_orders_per_month' => 100,
                'max_masters' => 1,
                'features' => ['email_notifications', 'basic_analytics'],
            ],
            'start' => [
                'max_orders_per_month' => 1000,
                'max_masters' => 3,
                'features' => ['telegram_notifications', 'payment_providers', 'email_notifications', 'basic_analytics'],
            ],
            'business' => [
                'max_orders_per_month' => null,
                'max_masters' => null,
                'features' => ['telegram_notifications', 'payment_providers', 'priority_support', 'custom_widget', 'export_data', 'advanced_analytics'],
            ],
            'pro' => [
                'max_orders_per_month' => null,
                'max_masters' => null,
                'features' => ['all_features', 'api_access', 'white_label', 'personal_manager', 'custom_integration'],
            ],
            default => [
                'max_orders_per_month' => 0,
                'max_masters' => 0,
                'features' => [],
            ],
        };
    }

    public function hasFeature(string $feature): bool
    {
        $limits = $this->getPlanLimits();
        return in_array($feature, $limits['features']) || in_array('all_features', $limits['features']);
    }

    public function getPlanPrice(): int
    {
        return match($this->subscription_plan) {
            'micro' => 50000,
            'start' => 100000,
            'business' => 150000,
            'pro' => 300000,
            default => 0,
        };
    }
}
