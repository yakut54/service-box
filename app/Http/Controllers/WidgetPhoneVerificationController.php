<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class WidgetPhoneVerificationController extends Controller
{
    /**
     * Request OTP code for phone verification.
     *
     * POST /api/widget/phone/request-code
     * Body: { phone: "+79134799312" }
     *
     * Generates a 4-digit code, stores in cache, returns masked phone.
     * In production, this would send SMS/Telegram. For now, returns code in response
     * (dev mode) or stores it for verification.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|max:20',
        ]);

        $phone = $request->phone;
        $ip = $request->ip();
        $shopId = $request->header('X-Shop-ID', 'unknown');

        // Rate limit: max 3 code requests per phone per 5 minutes
        $phoneKey = "otp-request:{$shopId}:{$phone}";
        if (RateLimiter::tooManyAttempts($phoneKey, 3)) {
            return response()->json([
                'error' => 'Слишком много запросов кода. Попробуйте позже.',
                'retry_after' => RateLimiter::availableIn($phoneKey),
            ], 429);
        }
        RateLimiter::hit($phoneKey, 300); // 5 min window

        // Rate limit: max 10 code requests per IP per 10 minutes
        $ipKey = "otp-request-ip:{$ip}";
        if (RateLimiter::tooManyAttempts($ipKey, 10)) {
            return response()->json([
                'error' => 'Слишком много запросов. Попробуйте позже.',
                'retry_after' => RateLimiter::availableIn($ipKey),
            ], 429);
        }
        RateLimiter::hit($ipKey, 600);

        // TODO: подключить реальную отправку SMS — сейчас код никуда не
        // уходит, кроме _dev_code в ответе (и то только вне production).
        //
        // КРИТИЧНО: раньше здесь был захардкожен код '1111' одинаковый для
        // всех телефонов на ЛЮБОМ окружении, включая боевой сервер
        // (APP_ENV=production), и отдавался в ответе всем подряд через
        // _dev_code. Это позволяло захватить аккаунт ЛЮБОГО покупателя,
        // зная только его номер телефона: запросить код, ввести "1111",
        // получить 60-дневный session_token — доступ к профилю, адресам
        // (включая квартиру), истории заказов. Проверено вживую на
        // shop_xdirmgfxyd7u 2026-08-22: получен полный профиль и оба
        // сохранённых адреса реального тестового покупателя без единого
        // SMS. Пока нет реальной отправки SMS, единственный безопасный
        // вариант — код случайный и НЕ раскрывается в проде: значит вход
        // по телефону в проде сейчас не работает по-настоящему, но это
        // лучше, чем работающий вход, который ломает любой аккаунт.
        $code = (string) random_int(1000, 9999);

        // Store in cache for 5 minutes
        $cacheKey = "otp:{$shopId}:{$phone}";
        Cache::put($cacheKey, [
            'code' => $code,
            'attempts' => 0,
            'ip' => $ip,
        ], 300);

        // Mask phone for display: +7 (913) ***-**-12
        $digits = preg_replace('/\D/', '', $phone);
        $masked = strlen($digits) >= 4
            ? substr($digits, 0, 4) . str_repeat('*', max(0, strlen($digits) - 6)) . substr($digits, -2)
            : $phone;

        return response()->json(array_filter([
            'message' => 'Код подтверждения отправлен',
            'masked_phone' => $masked,
            'expires_in' => 300,
            '_dev_code' => app()->environment('production') ? null : $code,
        ], fn ($v) => $v !== null));
    }

    /**
     * Verify OTP code and return a temporary access token.
     *
     * POST /api/widget/phone/verify
     * Body: { phone: "+79134799312", code: "1234" }
     *
     * Returns a short-lived token that grants access to phone-lookup endpoints.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string|max:20',
            'code' => 'required|string|size:4',
        ]);

        $phone = $request->phone;
        $shopId = $request->header('X-Shop-ID', 'unknown');
        $cacheKey = "otp:{$shopId}:{$phone}";

        $data = Cache::get($cacheKey);

        if (!$data) {
            return response()->json([
                'error' => 'Код истёк или не запрашивался. Запросите новый.',
            ], 400);
        }

        // Max 5 verification attempts
        if ($data['attempts'] >= 5) {
            Cache::forget($cacheKey);
            return response()->json([
                'error' => 'Слишком много попыток. Запросите новый код.',
            ], 429);
        }

        // Increment attempts
        $data['attempts']++;
        Cache::put($cacheKey, $data, 300);

        if (!hash_equals((string) $data['code'], (string) $request->code)) {
            $remaining = 5 - $data['attempts'];
            return response()->json([
                'error' => "Неверный код. Осталось попыток: {$remaining}",
            ], 400);
        }

        // Code is correct — clean up and issue token
        Cache::forget($cacheKey);

        // Create a temporary access token (valid 30 min) — историческое поведение
        // веб-виджета, разовая проверка "посмотреть мои заказы в этом сеансе".
        $token = bin2hex(random_bytes(32));
        $tokenKey = "phone-token:{$shopId}:{$token}";
        Cache::put($tokenKey, [
            'phone' => $phone,
            'ip' => $request->ip(),
        ], 1800); // 30 min

        // Долгая сессия для мобильного приложения (60 дней) — "запомнить меня",
        // выдаётся поверх той же SMS-проверки, не заменяет 30-минутный токен выше.
        //
        // Не используем Customer::findOrCreateByPhone() напрямую: при заходе
        // без единого заказа у клиента ещё нет имени, а customers.name — NOT
        // NULL. Передать туда заглушку тоже нельзя — findOrCreateByPhone молча
        // перезапишет ею настоящее имя, если клиент уже когда-то заказывал.
        $normalizedPhone = Customer::normalizePhone($phone);
        $customer = Customer::where('phone', $normalizedPhone)->first();
        if (!$customer) {
            $customer = Customer::create([
                'phone' => $normalizedPhone,
                'name' => 'Покупатель',
            ]);
        }
        $session = CustomerSession::issueFor($customer);

        return response()->json([
            'message' => 'Телефон подтверждён',
            'token' => $token,
            'expires_in' => 1800,
            'session_token' => $session->token,
            'session_expires_in' => (int) now()->diffInSeconds($session->expires_at),
        ]);
    }
}
