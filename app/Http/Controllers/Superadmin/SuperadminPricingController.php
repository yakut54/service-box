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
            'plan'          => $p->plan,
            'price_kopecks' => $p->price_kopecks,
            'price_rubles'  => round($p->price_kopecks / 100, 2),
        ]);

        return response()->json($pricing);
    }

    // PUT /api/superadmin/pricing
    public function update(Request $request)
    {
        $data = $request->validate([
            '*.plan'          => ['required', 'string'],
            '*.price_kopecks' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($data as $item) {
            PlanPricing::where('plan', $item['plan'])
                ->update(['price_kopecks' => $item['price_kopecks']]);
        }

        return response()->json(['message' => 'Pricing updated']);
    }
}
