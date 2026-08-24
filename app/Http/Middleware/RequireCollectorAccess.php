<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireCollectorAccess
{
    /**
     * Routes accessible to the collector role within /api/admin/*.
     * Everything else returns 403 — сборщик не должен видеть финансовую
     * часть админки (дашборд, аналитику, клиентов, настройки), только то,
     * что нужно для сборки заказа. Тот же паттерн, что и у RequireNotMaster.
     *
     * Format: ['HTTP_METHOD', 'url_pattern']
     */
    private const COLLECTOR_ALLOWED = [
        ['GET',   'api/admin/shop'],
        ['GET',   'api/admin/orders'],
        ['GET',   'api/admin/orders/*'],
        ['PATCH', 'api/admin/orders/*/status'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('staff_role') !== 'collector') {
            return $next($request);
        }

        foreach (self::COLLECTOR_ALLOWED as [$method, $pattern]) {
            if ($request->isMethod($method) && $request->is($pattern)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Доступ запрещён',
        ], 403);
    }
}
