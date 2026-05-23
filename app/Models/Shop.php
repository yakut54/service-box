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
        'subscription_plan',
        'subscription_expires_at',
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
    ];

    protected $casts = [
        'telegram_bot_connected' => 'boolean',
        'max_bot_connected'      => 'boolean',
        'max_chat_id'            => 'integer',
        'subscription_expires_at' => 'datetime',
        'widget_config'     => 'array',
        'legal_config'      => 'array',
        'delivery_settings' => 'array',
        'slot_duration'      => 'integer',
        'min_booking_notice'  => 'integer',
        'prepayment_enabled'    => 'boolean',
        'prepayment_amount'     => 'integer',
        'hide_customer_phone'   => 'boolean',
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
        $expired = ['max_orders_per_month' => 0, 'max_masters' => 0, 'features' => [], 'capabilities' => []];

        if (!$this->subscription_plan) {
            return $expired;
        }

        if (!$this->hasActiveSubscription()) {
            return $expired;
        }

        $pricing = PlanPricing::where('plan', $this->subscription_plan)->first();

        if (!$pricing) {
            return $expired;
        }

        return [
            'max_orders_per_month' => $pricing->max_orders_per_month,
            'max_masters'          => $pricing->max_masters,
            'features'             => $pricing->features      ?? [],
            'capabilities'         => $pricing->capabilities  ?? [],
        ];
    }

    public function enforceMasterLimit(): void
    {
        $limits = $this->getPlanLimits();
        $max    = $limits['max_masters'];

        if ($max === null) {
            return;
        }

        \App\Services\TenantService::inContext($this, function () use ($max) {
            $activeIds = \App\Models\Master::where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id');

            if ($activeIds->count() <= $max) {
                return;
            }

            $toDeactivate = $activeIds->slice($max)->values();
            \App\Models\Master::whereIn('id', $toDeactivate)->update(['is_active' => false]);

            $schema     = $this->schema_name;
            $allHidden  = [];
            foreach ($toDeactivate as $masterId) {
                $hidden = \App\Services\MasterCascadeService::handleDeactivation($masterId, $schema, $this);
                $allHidden = array_merge($allHidden, $hidden);
            }
            \App\Services\MasterCascadeService::notifyDeactivation($this, array_unique($allHidden));
        });
    }

    public function hasFeature(string $feature): bool
    {
        $limits = $this->getPlanLimits();
        $caps   = $limits['capabilities'];
        return in_array($feature, $caps) || in_array('all_features', $caps);
    }

    public function hasLegalDocs(): bool
    {
        $cfg = $this->legal_config ?? [];
        return !empty($cfg['public_offer_text']) || !empty($cfg['privacy_policy_text']);
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
