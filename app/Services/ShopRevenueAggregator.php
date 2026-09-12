<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Единственное место в проекте, где выручка считается циклом по тенантным
 * схемам (`FROM "<schema>".orders`) — раньше этот же приём жил только в
 * SuperadminRevenueController, теперь используется и там, и панелью сети.
 * Платформа считает КОМИССИЮ (`commission_amount`), сеть — СВОЙ ОБОРОТ
 * (`total_price`); оба вызывают одни и те же методы, просто с разной
 * колонкой и разным набором отбрасываемых статусов.
 *
 * При росте числа шоперов цикл по схемам можно будет заменить на один
 * UNION-запрос — сейчас магазинов мало, цикл дешевле по коду (тот же довод,
 * что был у исходного SuperadminRevenueController).
 */
final class ShopRevenueAggregator
{
    /**
     * @param Collection<int, Shop> $shops
     * @param string $column 'total_price' (оборот) | 'commission_amount' (комиссия платформы)
     * @param string[] $excludeStatuses статусы заказов, которые не считаем (например ['cancelled'])
     * @return array{
     *     total_kopecks: int,
     *     period_kopecks: int,
     *     period_days: int,
     *     orders_total: int,
     *     per_shop: array<string, array{name: string, total_kopecks: int, period_kopecks: int, orders: int}>
     * }
     */
    public static function totals(Collection $shops, string $column, array $excludeStatuses, int $periodDays): array
    {
        $column = self::validateColumn($column);
        $since  = now()->subDays($periodDays);

        $totalKopecks  = 0;
        $periodKopecks = 0;
        $ordersTotal   = 0;
        $perShop       = [];

        foreach ($shops as $shop) {
            $schema = self::validateSchema($shop->schema_name);
            if ($schema === null) {
                continue;
            }

            $row = DB::selectOne("
                SELECT
                    COALESCE(SUM({$column}), 0) AS total,
                    COALESCE(SUM({$column}) FILTER (WHERE created_at >= ?), 0) AS period,
                    COUNT(*) AS orders
                FROM \"{$schema}\".orders
                WHERE status NOT IN (" . self::placeholders($excludeStatuses) . ')
            ', array_merge([$since], $excludeStatuses));

            if ($row === null) {
                continue;
            }

            $totalKopecks  += (int) $row->total;
            $periodKopecks += (int) $row->period;
            $ordersTotal   += (int) $row->orders;

            $perShop[$shop->id] = [
                'name'           => $shop->name,
                'total_kopecks'  => (int) $row->total,
                'period_kopecks' => (int) $row->period,
                'orders'         => (int) $row->orders,
            ];
        }

        return [
            'total_kopecks'  => $totalKopecks,
            'period_kopecks' => $periodKopecks,
            'period_days'    => $periodDays,
            'orders_total'   => $ordersTotal,
            'per_shop'       => $perShop,
        ];
    }

    /**
     * Точки для графика — контракт совпадает с тем, что уже рисует
     * admin/src/components/RevenueChart.vue (`{date, orders, revenue}`).
     * Дни без заказов досыпаются нулями тем же приёмом, что и
     * OrderController::chart (там же для одного магазина, не для сети).
     *
     * @param Collection<int, Shop> $shops
     * @param string[] $excludeStatuses
     * @return list<array{date: string, orders: int, revenue: int}>
     */
    public static function dailySeries(Collection $shops, int $days, array $excludeStatuses): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $byDate = [];

        foreach ($shops as $shop) {
            $schema = self::validateSchema($shop->schema_name);
            if ($schema === null) {
                continue;
            }

            $rows = DB::select("
                SELECT DATE(created_at) AS date, COUNT(*) AS orders, COALESCE(SUM(total_price), 0) AS revenue
                FROM \"{$schema}\".orders
                WHERE created_at >= ? AND status NOT IN (" . self::placeholders($excludeStatuses) . ')
                GROUP BY DATE(created_at)
            ', array_merge([$from], $excludeStatuses));

            foreach ($rows as $row) {
                $date = (string) $row->date;
                $byDate[$date]['orders']  = ($byDate[$date]['orders']  ?? 0) + (int) $row->orders;
                $byDate[$date]['revenue'] = ($byDate[$date]['revenue'] ?? 0) + (int) $row->revenue;
            }
        }

        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $result[] = [
                'date'    => $date,
                'orders'  => $byDate[$date]['orders']  ?? 0,
                'revenue' => $byDate[$date]['revenue'] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * $perShopLimit ограничивает, сколько строк тянуть из КАЖДОЙ схемы (не
     * пускать одну точку с тысячами заказов флудить общий список), $totalLimit
     * — сколько оставить после сортировки по всем магазинам разом.
     *
     * @param Collection<int, Shop> $shops
     * @param string $column 'total_price' | 'commission_amount'
     * @return Collection<int, array{shop_id: string, shop_name: string, order_id: string, amount_kopecks: int, total_kopecks: int, status: string, created_at: string}>
     */
    public static function recentOrders(Collection $shops, string $column, int $perShopLimit, int $totalLimit): Collection
    {
        $column = self::validateColumn($column);
        $result = collect();

        foreach ($shops as $shop) {
            $schema = self::validateSchema($shop->schema_name);
            if ($schema === null) {
                continue;
            }

            $orders = DB::select("
                SELECT id, {$column} AS amount, total_price, status, created_at
                FROM \"{$schema}\".orders
                WHERE {$column} > 0
                ORDER BY created_at DESC
                LIMIT ?
            ", [$perShopLimit]);

            foreach ($orders as $o) {
                $result->push([
                    'shop_id'        => $shop->id,
                    'shop_name'      => $shop->name,
                    'order_id'       => $o->id,
                    'amount_kopecks' => (int) $o->amount,
                    'total_kopecks'  => (int) $o->total_price,
                    'status'         => $o->status,
                    'created_at'     => $o->created_at,
                ]);
            }
        }

        return $result->sortByDesc('created_at')->take($totalLimit)->values();
    }

    // ── Private ──────────────────────────────────────────────────────────────

    private static function validateColumn(string $column): string
    {
        return match ($column) {
            'total_price', 'commission_amount' => $column,
            default => throw new \InvalidArgumentException("Unsupported revenue column: {$column}"),
        };
    }

    /**
     * schema_name приходит из БД, а не от пользователя, но всё равно
     * интерполируется в сырой SQL ниже — проверяем формат как защиту в
     * глубину, а не потому что не доверяем самой таблице shops.
     */
    private static function validateSchema(string $schemaName): ?string
    {
        if (preg_match('/^shop_[a-z0-9_]+$/', $schemaName) === 1) {
            return $schemaName;
        }

        Log::warning('ShopRevenueAggregator: skipped shop with suspicious schema_name', [
            'schema_name' => $schemaName,
        ]);

        return null;
    }

    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
