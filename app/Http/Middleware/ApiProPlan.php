<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiProPlan
{
    public function handle(Request $request, Closure $next): Response
    {
        $shop = $request->get('_shop');

        if (!$shop || !$shop->hasFeature('api_access')) {
            return response()->json([
                'error'   => 'Доступ запрещён',
                'message' => 'Доступ к API доступен только на тарифе Pro.',
            ], 403);
        }

        return $next($request);
    }
}
