<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\TableExport;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Excel-отчёт по комиссии для бухгалтерии/отчётности — те же условия
     * выборки, что в index(), но без limit(20): там лимит для превью в
     * кабинете, в отчёте нужны все строки.
     *
     * GET /api/admin/commission/export
     */
    public function export(): StreamedResponse
    {
        $statusLabels = [
            'pending'         => 'Ожидает',
            'paid'            => 'Оплачен',
            'processing'      => 'В работе',
            'completed'       => 'Завершён',
            'cancelled'       => 'Отменён',
            'needs_attention' => 'Требует внимания',
        ];

        $orders = Order::query()
            ->where('status', '!=', 'cancelled')
            ->where('status', '!=', 'pending')
            ->where('commission_amount', '>', 0)
            ->orderByDesc('created_at')
            ->get(['id', 'total_price', 'commission_amount', 'status', 'created_at']);

        $headers = ['Дата', 'Номер', 'Сумма заказа (₽)', 'Комиссия (₽)', 'Статус'];

        $rows = $orders->map(fn ($o) => [
            $o->created_at->format('d.m.Y H:i'),
            strtoupper(substr($o->id, 0, 8)),
            number_format($o->total_price / 100, 2, '.', ''),
            number_format($o->commission_amount / 100, 2, '.', ''),
            $statusLabels[$o->status] ?? $o->status,
        ]);

        return TableExport::stream('xlsx', 'commission', $headers, $rows);
    }
}
