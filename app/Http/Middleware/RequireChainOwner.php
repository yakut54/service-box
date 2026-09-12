<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireChainOwner
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user()?->is_chain_owner) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        return $next($request);
    }
}
