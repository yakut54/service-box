<?php

namespace App\Http\Controllers\Chain;

use App\Http\Controllers\Controller;
use App\Services\ShopRevenueAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ChainRevenueController extends Controller
{
    // GET /api/chain/revenue?days=30
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $days = min((int) $request->input('days', 30), 90);

        $data = Cache::remember("chain:revenue:{$user->id}:{$days}", 60, function () use ($user, $days) {
            $shops = $user->shops()->get();

            // Оборот сети (total_price), не комиссия платформы. 'cancelled'
            // исключаем — как OrderController::stats считает для одной точки,
            // чтобы сумма по сети сходилась с тем, что владелец видит внутри
            // каждой своей точки на её собственном дашборде.
            $totals = ShopRevenueAggregator::totals($shops, 'total_price', ['cancelled'], $days);
            $chart  = ShopRevenueAggregator::dailySeries($shops, $days, ['cancelled']);

            $perShop = collect($totals['per_shop'])
                ->map(function (array $row, string $shopId) use ($totals) {
                    return [
                        'shop_id'         => $shopId,
                        'name'            => $row['name'],
                        'revenue_kopecks' => $row['period_kopecks'],
                        'orders'          => $row['period_orders'],
                        'share_percent'   => $totals['period_kopecks'] > 0
                            ? round($row['period_kopecks'] / $totals['period_kopecks'] * 100, 1)
                            : 0.0,
                    ];
                })
                ->sortByDesc('revenue_kopecks')
                ->values();

            return [
                'total_kopecks'  => $totals['total_kopecks'],
                'period_kopecks' => $totals['period_kopecks'],
                'period_days'    => $days,
                'orders_total'   => $totals['orders_total'],
                'period_orders'  => $totals['period_orders'],
                'shops_count'    => $shops->count(),
                'chart'          => $chart,
                'per_shop'       => $perShop,
            ];
        });

        return response()->json($data);
    }
}
