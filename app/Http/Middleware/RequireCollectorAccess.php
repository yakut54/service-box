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
     * что нужно для сборки заказа. Тот же принцип, что и у RequireNotMaster,
     * но БЕЗ glob-wildcard для orders/* — `orders/*` матчит не только
     * `orders/{uuid}`, но и `orders/stats`/`orders/chart`/`orders/export`
     * (те же строки registered раньше apiResource в routes/api.php), а это
     * ровно те финансовые данные, которые сборщику показывать нельзя.
     * Найдено живым тестом 2026-08-24 — вместо wildcard проверяем, что
     * сегмент после orders/ выглядит как UUID.
     */
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('staff_role') !== 'collector') {
            return $next($request);
        }

        $path = $request->path();

        $allowed =
            ($request->isMethod('GET') && $path === 'api/admin/shop')
            || ($request->isMethod('GET') && $path === 'api/admin/orders')
            || ($request->isMethod('GET') && preg_match('#^api/admin/orders/' . self::UUID . '$#i', $path) === 1)
            || ($request->isMethod('PATCH') && preg_match('#^api/admin/orders/' . self::UUID . '/status$#i', $path) === 1);

        if ($allowed) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Доступ запрещён',
        ], 403);
    }
}
