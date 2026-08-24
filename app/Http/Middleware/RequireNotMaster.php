<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireNotMaster
{
    /**
     * Routes accessible to master role within /api/admin/*.
     * Everything else returns 403.
     *
     * ВАЖНО: раньше здесь был glob-wildcard `bookings/*`, который матчил не
     * только `bookings/{uuid}`, но и `bookings/stats`/`bookings/masters`/
     * `bookings/available-slots` (те же строки, registered раньше
     * apiResource в routes/api.php) — мастер мог читать общую аналитику по
     * записям всего магазина, не только свои. Найдено 2026-08-24 при
     * реализации точно такого же allow-листа для роли «сборщик» —
     * пофикшено тем же способом: проверяем, что сегмент после bookings/
     * выглядит как UUID, а не любой строкой.
     */
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('staff_role') !== 'master') {
            return $next($request);
        }

        $path = $request->path();

        $allowed =
            ($request->isMethod('GET') && $path === 'api/admin/shop')
            || ($request->isMethod('GET') && $path === 'api/admin/bookings')
            || ($request->isMethod('GET') && preg_match('#^api/admin/bookings/' . self::UUID . '$#i', $path) === 1)
            || ($request->isMethod('PATCH') && preg_match('#^api/admin/bookings/' . self::UUID . '$#i', $path) === 1);

        if ($allowed) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Доступ запрещён',
        ], 403);
    }
}
