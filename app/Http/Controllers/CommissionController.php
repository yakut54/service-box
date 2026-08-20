<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

/**
 * Комиссия платформы — плоские 20% с каждой оплаты (см. PLAN.md → «Тарифы
 * → плоская комиссия»). Один эндпоинт на кабинет шопера, без разбивки по
 * тарифам — ставка одна для всех.
 */
class CommissionController extends Controller
{
    // GET /api/admin/commission
    public function index(): JsonResponse
    {
        $paidQuery = Order::query()->where('status', '!=', 'cancelled')->where('status', '!=', 'pending');

        $totalKopecks = (clone $paidQuery)->sum('commission_amount');
        $last30dKopecks = (clone $paidQuery)->where('created_at', '>=', now()->subDays(30))->sum('commission_amount');
        $thisMonthKopecks = (clone $paidQuery)->where('created_at', '>=', now()->startOfMonth())->sum('commission_amount');

        $recentOrders = (clone $paidQuery)
            ->where('commission_amount', '>', 0)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'total_price', 'commission_amount', 'status', 'created_at']);

        return response()->json([
            'commission_percent'          => config('platform.commission_percent'),
            'min_order_amount_kopecks'    => config('platform.min_order_amount_kopecks'),
            'commission_total_kopecks'    => (int) $totalKopecks,
            'commission_total_rubles'     => round($totalKopecks / 100, 2),
            'commission_last_30d_kopecks' => (int) $last30dKopecks,
            'commission_last_30d_rubles'  => round($last30dKopecks / 100, 2),
            'commission_month_kopecks'    => (int) $thisMonthKopecks,
            'commission_month_rubles'     => round($thisMonthKopecks / 100, 2),
            'recent_orders'               => $recentOrders,
        ]);
    }
}
