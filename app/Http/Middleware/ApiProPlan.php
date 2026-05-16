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
                'error'   => 'Forbidden',
                'message' => 'API access is available on the Pro plan.',
            ], 403);
        }

        return $next($request);
    }
}
