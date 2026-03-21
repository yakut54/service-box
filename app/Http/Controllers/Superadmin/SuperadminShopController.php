<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\PlanPricing;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SuperadminShopController extends Controller
{
    // GET /api/superadmin/shops
    public function index(Request $request)
    {
        $query = Shop::query()
            ->with('user:id,name,email,created_at')
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('domain', 'ilike', "%{$search}%");
            });
        }

        if ($plan = $request->query('plan')) {
            $query->where('plan', $plan);
        }

        $shops = $query->paginate(25);

        return response()->json($shops);
    }

    // GET /api/superadmin/shops/{id}
    public function show(string $id)
    {
        $shop = Shop::with('user:id,name,email,created_at,is_superadmin')->findOrFail($id);

        return response()->json($shop);
    }

    // PATCH /api/superadmin/shops/{id}/plan
    public function updatePlan(Request $request, string $id)
    {
        $shop = Shop::findOrFail($id);

        $data = $request->validate([
            'plan'             => ['required', Rule::in(['micro', 'start', 'business', 'pro'])],
            'subscription_ends_at' => ['nullable', 'date'],
        ]);

        $shop->update($data);

        return response()->json(['message' => 'Plan updated', 'shop' => $shop->fresh()]);
    }
}
