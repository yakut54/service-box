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
     * Format: ['HTTP_METHOD', 'url_pattern']
     */
    private const MASTER_ALLOWED = [
        ['GET',   'api/admin/shop'],
        ['GET',   'api/admin/bookings'],
        ['GET',   'api/admin/bookings/*'],
        ['PATCH', 'api/admin/bookings/*'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('staff_role') !== 'master') {
            return $next($request);
        }

        foreach (self::MASTER_ALLOWED as [$method, $pattern]) {
            if ($request->isMethod($method) && $request->is($pattern)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Доступ запрещён',
        ], 403);
    }
}
