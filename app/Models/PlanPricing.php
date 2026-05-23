<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanPricing extends Model
{
    protected $table      = 'plan_pricing';
    protected $primaryKey = 'plan';
    public    $incrementing = false;
    protected $keyType    = 'string';

    protected $fillable = [
        'plan',
        'price_kopecks',
        'max_orders_per_month',
        'max_masters',
        'features',
        'capabilities',
    ];

    protected $casts = [
        'price_kopecks'        => 'integer',
        'max_orders_per_month' => 'integer',
        'max_masters'          => 'integer',
        'features'             => 'array',
        'capabilities'         => 'array',
    ];

    public static function forPlan(string $plan): int
    {
        return static::where('plan', $plan)->value('price_kopecks') ?? 0;
    }

    public static function allAsMap(): array
    {
        return static::all()->keyBy('plan')->map(fn($p) => $p->price_kopecks)->toArray();
    }
}
