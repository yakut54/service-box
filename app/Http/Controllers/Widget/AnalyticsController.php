<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    private const ALLOWED_EVENTS = [
        'widget_opened',
        'catalog_viewed',
        'service_selected',
        'booking_started',
        'booking_step_phone',
        'booking_completed',
        'booking_abandoned',
    ];

    public function store(Request $request): JsonResponse
    {
        $shopId = $request->header('X-Shop-ID');
        $shop = Shop::where('api_key', $shopId)->first();

        if (!$shop) {
            return response()->json(['error' => 'Магазин не найден'], 404);
        }

        $validated = $request->validate([
            'event'      => 'required|string|max:50',
            'session_id' => 'required|string|max:100',
            'product_id' => 'nullable|uuid',
            'meta'       => 'nullable|array',
        ]);

        if (!in_array($validated['event'], self::ALLOWED_EVENTS)) {
            return response()->json(['ok' => true]); // silently ignore unknown events
        }

        $schema = $this->safeSchema($shop->schema_name);

        DB::statement("
            INSERT INTO {$schema}.widget_analytics (session_id, event, product_id, meta)
            VALUES (?, ?, ?, ?)
        ", [
            $validated['session_id'],
            $validated['event'],
            $validated['product_id'] ?? null,
            json_encode($validated['meta'] ?? []),
        ]);

        return response()->json(['ok' => true]);
    }

    public function funnel(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        if (!$shop) {
            return response()->json(['error' => 'Магазин не найден'], 404);
        }

        $days = (int) $request->query('days', 30);
        $days = in_array($days, [7, 30, 90]) ? $days : 30;

        $schema = $this->safeSchema($shop->schema_name);

        $rows = DB::select("
            SELECT event, COUNT(DISTINCT session_id) AS sessions
            FROM {$schema}.widget_analytics
            WHERE created_at >= NOW() - (? * INTERVAL '1 day')
            GROUP BY event
        ", [$days]);

        $counts = collect($rows)->pluck('sessions', 'event')->map(fn($v) => (int) $v);

        $funnel = [
            ['event' => 'widget_opened',      'label' => 'Открыли виджет'],
            ['event' => 'catalog_viewed',     'label' => 'Просмотрели каталог'],
            ['event' => 'service_selected',   'label' => 'Выбрали услугу'],
            ['event' => 'booking_started',    'label' => 'Начали запись'],
            ['event' => 'booking_step_phone', 'label' => 'Ввели телефон'],
            ['event' => 'booking_completed',  'label' => 'Записались'],
        ];

        $top = $counts->get('widget_opened', 0);
        $result = array_map(function ($step) use ($counts, $top) {
            $sessions = $counts->get($step['event'], 0);
            return [
                'event'    => $step['event'],
                'label'    => $step['label'],
                'sessions' => $sessions,
                'pct_top'  => $top > 0 ? round($sessions / $top * 100) : 0,
            ];
        }, $funnel);

        return response()->json([
            'days'   => $days,
            'funnel' => $result,
        ]);
    }

    private function safeSchema(string $schema): string
    {
        if (!preg_match('/^shop_[a-z0-9_]+$/', $schema)) {
            abort(500, 'Некорректное имя схемы');
        }
        return $schema;
    }
}
