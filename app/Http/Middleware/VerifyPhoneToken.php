<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyPhoneToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Phone-Token') ?? $request->query('_token');
        $shopId = $request->header('X-Shop-ID', 'unknown');

        if (!$token) {
            return response()->json([
                'error' => 'Требуется подтверждение телефона',
                'code' => 'phone_verification_required',
            ], 401);
        }

        $tokenKey = "phone-token:{$shopId}:{$token}";
        $data = Cache::get($tokenKey);

        if (!$data) {
            return response()->json([
                'error' => 'Сессия истекла. Подтвердите телефон заново.',
                'code' => 'phone_token_expired',
            ], 401);
        }

        // Ensure the phone in the request matches the token's phone
        $requestPhone = $request->query('phone') ?? $request->input('phone');
        if ($requestPhone && $data['phone'] !== $requestPhone) {
            return response()->json([
                'error' => 'Токен не соответствует номеру телефона',
                'code' => 'phone_mismatch',
            ], 403);
        }

        // Inject verified phone into request for controllers
        $request->merge(['verified_phone' => $data['phone']]);

        return $next($request);
    }
}
