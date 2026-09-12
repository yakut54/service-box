<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\ShopRevenueAggregator;

/**
 * Выручка платформы = сумма комиссии (20%, см. PLAN.md) со всех заказов
 * всех магазинов. Считает App\Services\ShopRevenueAggregator — тот же
 * агрегатор использует панель сети (Chain\ChainRevenueController), там
 * вместо комиссии платформы считается оборот самой сети.
 */
class SuperadminRevenueController extends Controller
{
    // GET /api/superadmin/revenue
    public function index()
    {
        $shops = Shop::select('id', 'name', 'schema_name', 'created_at')->get();

        $totals = ShopRevenueAggregator::totals($shops, 'commission_amount', ['pending', 'cancelled'], 30);

        $recentOrders = ShopRevenueAggregator::recentOrders($shops, 'commission_amount', 5, 20)
            ->map(fn (array $o) => [
                'shop_name'          => $o['shop_name'],
                'order_id'           => $o['order_id'],
                'commission_kopecks' => $o['amount_kopecks'],
                'total_kopecks'      => $o['total_kopecks'],
                'status'             => $o['status'],
                'created_at'         => $o['created_at'],
            ]);

        return response()->json([
            'commission_total_kopecks'    => $totals['total_kopecks'],
            'commission_total_rubles'     => round($totals['total_kopecks'] / 100, 2),
            'commission_last_30d_kopecks' => $totals['period_kopecks'],
            'commission_last_30d_rubles'  => round($totals['period_kopecks'] / 100, 2),
            'total_shops'                 => $shops->count(),
            'new_shops_30d'               => $shops->where('created_at', '>=', now()->subDays(30))->count(),
            'recent_orders'               => $recentOrders,
        ]);
    }
}
