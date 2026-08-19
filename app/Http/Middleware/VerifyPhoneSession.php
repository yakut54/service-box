<?php

namespace App\Http\Middleware;

use App\Models\CustomerSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Долгая сессия мобильного приложения (60 дней) — отдельно от 30-минутного
 * OTP-токена веб-виджета (см. VerifyPhoneToken). Байер проходит SMS один раз
 * и остаётся в системе, пока сам не выйдет или сессия не истечёт сама.
 */
class VerifyPhoneSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Phone-Session');

        if (!$token) {
            return response()->json([
                'error' => 'Требуется вход по телефону',
                'code' => 'phone_session_required',
            ], 401);
        }

        $session = CustomerSession::with('customer')
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (!$session || !$session->customer) {
            return response()->json([
                'error' => 'Сессия истекла. Войдите заново.',
                'code' => 'phone_session_expired',
            ], 401);
        }

        $request->attributes->set('customer', $session->customer);

        return $next($request);
    }
}
