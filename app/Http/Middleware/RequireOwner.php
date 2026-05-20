<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('staff_role') !== 'owner') {
            return response()->json([
                'message' => 'Доступно только владельцу магазина',
            ], 403);
        }

        return $next($request);
    }
}
