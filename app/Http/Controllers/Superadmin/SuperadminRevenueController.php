<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * Выручка платформы = сумма комиссии (20%, см. PLAN.md) со всех заказов
 * всех магазинов. orders живёт в тенантной схеме каждого шопера, не в
 * public — считаем циклом по схемам (тот же приём, что и в cascadeDebug
 * этого же неймспейса). При росте числа шоперов можно будет оптимизировать
 * до одного UNION-запроса, сейчас магазинов мало — цикл дешевле по коду.
 */
class SuperadminRevenueController extends Controller
{
    // GET /api/superadmin/revenue
    public function index()
    {
        $shops = Shop::select('id', 'name', 'schema_name', 'created_at')->get();

        $totalKopecks   = 0;
        $last30dKopecks = 0;
        $recentOrders   = collect();

        foreach ($shops as $shop) {
            $schema = $shop->schema_name;

            $totals = DB::selectOne('
                SELECT
                    COALESCE(SUM(commission_amount), 0) AS total,
                    COALESCE(SUM(commission_amount) FILTER (WHERE created_at >= ?), 0) AS last_30d
                FROM "'.$schema.'".orders
                WHERE status NOT IN (\'pending\', \'cancelled\')
            ', [now()->subDays(30)]);

            $totalKopecks   += (int) $totals->total;
            $last30dKopecks += (int) $totals->last_30d;

            $orders = DB::select('
                SELECT id, commission_amount, total_price, status, created_at
                FROM "'.$schema.'".orders
                WHERE commission_amount > 0
                ORDER BY created_at DESC
                LIMIT 5
            ');

            foreach ($orders as $o) {
                $recentOrders->push([
                    'shop_name'          => $shop->name,
                    'order_id'           => $o->id,
                    'commission_kopecks' => (int) $o->commission_amount,
                    'total_kopecks'      => (int) $o->total_price,
                    'status'             => $o->status,
                    'created_at'         => $o->created_at,
                ]);
            }
        }

        $recentOrders = $recentOrders->sortByDesc('created_at')->take(20)->values();

        return response()->json([
            'commission_total_kopecks'    => $totalKopecks,
            'commission_total_rubles'     => round($totalKopecks / 100, 2),
            'commission_last_30d_kopecks' => $last30dKopecks,
            'commission_last_30d_rubles'  => round($last30dKopecks / 100, 2),
            'total_shops'                 => $shops->count(),
            'new_shops_30d'               => $shops->where('created_at', '>=', now()->subDays(30))->count(),
            'recent_orders'               => $recentOrders,
        ]);
    }
}
