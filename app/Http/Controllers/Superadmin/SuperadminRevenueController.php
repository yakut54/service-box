<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\PlanPricing;
use Illuminate\Support\Facades\DB;

class SuperadminRevenueController extends Controller
{
    // GET /api/superadmin/revenue
    public function index()
    {
        $pricing = PlanPricing::allAsMap(); // ['micro' => 150000, ...]

        // Shops by plan (avoid reserved word 'count' as alias)
        $byPlan = Shop::query()
            ->select('subscription_plan as plan', DB::raw('count(*) as shop_count'))
            ->whereNotNull('subscription_plan')
            ->groupBy('subscription_plan')
            ->pluck('shop_count', 'plan')
            ->toArray();

        // MRR: sum of price_kopecks per active plan
        $mrrKopecks = 0;
        foreach ($byPlan as $plan => $cnt) {
            $mrrKopecks += ($pricing[$plan] ?? 0) * $cnt;
        }

        // Total shops
        $totalShops = Shop::count();

        // New shops last 30 days
        $newShops30d = Shop::where('created_at', '>=', now()->subDays(30))->count();

        // Recent payments from subscription_payments table
        $recentPayments = DB::table('subscription_payments')
            ->join('shops', 'subscription_payments.shop_id', '=', 'shops.id')
            ->select(
                'subscription_payments.id',
                DB::raw('subscription_payments.amount * 100 as amount_kopecks'),
                'subscription_payments.status',
                'subscription_payments.created_at',
                'shops.name as shop_name',
                'subscription_payments.plan as plan'
            )
            ->where('subscription_payments.status', 'succeeded')
            ->orderByDesc('subscription_payments.created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'mrr_kopecks'     => $mrrKopecks,
            'mrr_rubles'      => round($mrrKopecks / 100, 2),
            'total_shops'     => $totalShops,
            'new_shops_30d'   => $newShops30d,
            'shops_by_plan'   => $byPlan,
            'recent_payments' => $recentPayments,
        ]);
    }
}
