<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireSuperadmin
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user()?->is_superadmin) {
            return response()->json(['message' => 'Доступ запрещён'], 403);
        }

        return $next($request);
    }
}
