<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\PlanPricing;
use Illuminate\Http\Request;

class SuperadminPricingController extends Controller
{
    // GET /api/superadmin/pricing
    public function index()
    {
        $pricing = PlanPricing::all()->keyBy('plan')->map(fn($p) => [
            'plan'                 => $p->plan,
            'price_kopecks'        => $p->price_kopecks,
            'price_rubles'         => round($p->price_kopecks / 100, 2),
            'max_orders_per_month' => $p->max_orders_per_month,
            'max_masters'          => $p->max_masters,
            'features'             => $p->features ?? [],
        ]);

        return response()->json($pricing);
    }

    // PUT /api/superadmin/pricing
    public function update(Request $request)
    {
        $data = $request->validate([
            '*.plan'                 => ['required', 'string'],
            '*.price_kopecks'        => ['required', 'integer', 'min:0'],
            '*.max_orders_per_month' => ['nullable', 'integer', 'min:1'],
            '*.max_masters'          => ['nullable', 'integer', 'min:1'],
            '*.features'             => ['nullable', 'array'],
            '*.features.*'           => ['string', 'max:200'],
        ]);

        foreach ($data as $item) {
            PlanPricing::where('plan', $item['plan'])->update([
                'price_kopecks'        => $item['price_kopecks'],
                'max_orders_per_month' => $item['max_orders_per_month'] ?? null,
                'max_masters'          => $item['max_masters'] ?? null,
                'features'             => json_encode($item['features'] ?? []),
            ]);
        }

        return response()->json(['message' => 'Pricing updated']);
    }
}
