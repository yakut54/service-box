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

        // Shops by plan
        $byPlan = Shop::query()
            ->select('plan', DB::raw('count(*) as count'))
            ->whereNotNull('plan')
            ->groupBy('plan')
            ->pluck('count', 'plan')
            ->toArray();

        // MRR: sum of price_kopecks per active plan
        $mrrKopecks = 0;
        foreach ($byPlan as $plan => $count) {
            $mrrKopecks += ($pricing[$plan] ?? 0) * $count;
        }

        // Total shops
        $totalShops = Shop::count();

        // New shops last 30 days
        $newShops30d = Shop::where('created_at', '>=', now()->subDays(30))->count();

        // Recent payments (from platform subscriptions)
        $recentPayments = DB::table('payments')
            ->join('shops', 'payments.shop_id', '=', 'shops.id')
            ->select(
                'payments.id',
                'payments.amount_kopecks',
                'payments.status',
                'payments.created_at',
                'shops.name as shop_name',
                'shops.plan'
            )
            ->where('payments.type', 'subscription')
            ->where('payments.status', 'succeeded')
            ->orderByDesc('payments.created_at')
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
