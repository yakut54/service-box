<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
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
            $query->where('subscription_plan', $plan);
        }

        $shops = $query->paginate(25)->through(fn($s) => [
            'id'                     => $s->id,
            'name'                   => $s->name,
            'domain'                 => $s->domain,
            'plan'                   => $s->subscription_plan,
            'subscription_ends_at'   => $s->subscription_expires_at,
            'created_at'             => $s->created_at,
            'user'                   => $s->user,
        ]);

        return response()->json($shops);
    }

    // GET /api/superadmin/shops/{id}
    public function show(string $id)
    {
        $shop = Shop::with('user:id,name,email,created_at')->findOrFail($id);

        return response()->json([
            'id'                   => $shop->id,
            'name'                 => $shop->name,
            'domain'               => $shop->domain,
            'plan'                 => $shop->subscription_plan,
            'subscription_ends_at' => $shop->subscription_expires_at,
            'created_at'           => $shop->created_at,
            'user'                 => $shop->user,
        ]);
    }

    // PATCH /api/superadmin/shops/{id}/plan
    public function updatePlan(Request $request, string $id)
    {
        $shop = Shop::findOrFail($id);

        $data = $request->validate([
            'plan'                 => ['required', Rule::in(['micro', 'start', 'business', 'pro'])],
            'subscription_ends_at' => ['nullable', 'date'],
        ]);

        $expiresAt = $data['subscription_ends_at']
            ?? ($shop->hasActiveSubscription() ? $shop->subscription_expires_at : now()->addYear());

        $shop->update([
            'subscription_plan'       => $data['plan'],
            'subscription_expires_at' => $expiresAt,
        ]);

        $shop->refresh();
        $shop->enforceMasterLimit();

        return response()->json([
            'message' => 'Plan updated',
            'shop'    => [
                'id'                   => $shop->id,
                'name'                 => $shop->name,
                'plan'                 => $shop->subscription_plan,
                'subscription_ends_at' => $shop->subscription_expires_at,
            ],
        ]);
    }
}
