<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Mail\ResetPasswordMail;
use App\Models\Shop;
use App\Models\ShopStaff;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * POST /api/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $user = User::create([
                'name'               => $request->name,
                'email'              => $request->email,
                'password'           => Hash::make($request->password),
                'terms_accepted_at'  => now(),
                'terms_accepted_ip'  => $request->ip(),
            ]);

            $schemaName = 'shop_' . strtolower(Str::random(12));

            $shop = Shop::create([
                'user_id' => $user->id,
                'name' => $request->shop_name,
                'domain' => $request->shop_domain,
                'schema_name' => $schemaName,
                'subscription_plan' => 'micro',
                'subscription_expires_at' => now()->addDays(14),
                'timezone' => $request->timezone ?? 'Europe/Moscow',
            ]);

            TenantService::createSchema($schemaName);

            $token = $user->createToken('auth_token')->plainTextToken;

            DB::commit();

            return response()->json([
                'message' => 'Регистрация выполнена успешно',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'shop' => [
                    'id' => $shop->id,
                    'name' => $shop->name,
                    'api_key' => $shop->api_key,
                    'subscription_plan' => $shop->subscription_plan,
                    'timezone' => $shop->timezone,
                ],
                'token' => $token,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Ошибка регистрации. Попробуйте ещё раз',
            ], 500);
        }
    }

    /**
     * POST /api/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Неверный email или пароль',
            ], 401);
        }

        $user = Auth::user();

        [$shop, $role] = $this->resolveShopAndRole($user);

        if (!$shop) {
            return response()->json([
                'message' => 'Магазин не найден',
            ], 404);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Вход выполнен',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_superadmin' => (bool) $user->is_superadmin,
                'role' => $role,
            ],
            'shop' => $this->shopPayload($shop),
            'token' => $token,
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Выход выполнен',
        ]);
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        [$shop, $role] = $this->resolveShopAndRole($user);

        if (!$shop) {
            return response()->json(['message' => 'Магазин не найден'], 404);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_superadmin' => (bool) $user->is_superadmin,
                'role' => $role,
            ],
            'shop' => array_merge($this->shopPayload($shop), [
                'domain'             => $shop->domain,
                'min_booking_notice' => $shop->min_booking_notice,
                'prepayment_enabled' => (bool) $shop->prepayment_enabled,
                'prepayment_amount'  => (int) $shop->prepayment_amount,
                'delivery_settings'  => $shop->delivery_settings,
            ]),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function resolveShopAndRole(User $user): array
    {
        // Owner
        if ($user->shop) {
            return [$user->shop, 'owner'];
        }

        // Staff member
        $staff = ShopStaff::where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->with('shop')
            ->first();

        if ($staff && $staff->shop) {
            return [$staff->shop, $staff->role];
        }

        return [null, null];
    }

    private function shopPayload(Shop $shop): array
    {
        return [
            'id'                     => $shop->id,
            'name'                   => $shop->name,
            'api_key'                => $shop->api_key,
            'subscription_plan'      => $shop->subscription_plan,
            'subscription_expires_at'=> $shop->subscription_expires_at,
            'widget_config'          => $shop->widget_config,
            'work_start'             => $shop->work_start,
            'work_end'               => $shop->work_end,
            'slot_duration'          => $shop->slot_duration,
            'timezone'               => $shop->timezone,
            'telegram_bot_connected' => $shop->telegram_bot_connected,
            'max_bot_connected'      => $shop->max_bot_connected,
            'max_chat_id'            => $shop->max_chat_id,
            'yookassa_shop_id'       => $shop->yookassa_shop_id,
            'legal_config'           => $shop->legal_config,
            'hide_customer_phone'    => (bool) $shop->hide_customer_phone,
        ];
    }

    /**
     * POST /api/auth/refresh
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->user()->currentAccessToken()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Токен обновлён',
            'token' => $token,
        ]);
    }

    /**
     * POST /api/auth/change-password  (requires auth)
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Текущий пароль неверен'], 422);
        }

        $user->update(['password' => Hash::make($request->password)]);
        $user->tokens()->delete();

        return response()->json(['message' => 'Пароль успешно изменён']);
    }

    /**
     * POST /api/auth/forgot-password  (public)
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Письмо со ссылкой для сброса пароля отправлено']);
        }

        $token    = Password::createToken($user);
        $resetUrl = rtrim(config('app.frontend_url'), '/') . '/reset-password'
            . '?token=' . $token
            . '&email=' . urlencode($user->email);

        Mail::to($user->email)->send(new ResetPasswordMail($resetUrl, $user->email));

        return response()->json(['message' => 'Письмо со ссылкой для сброса пароля отправлено']);
    }

    /**
     * POST /api/auth/reset-password  (public)
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            $messages = [
                Password::INVALID_TOKEN => 'Ссылка для сброса пароля недействительна или устарела',
                Password::INVALID_USER  => 'Аккаунт с таким email не найден',
                Password::RESET_THROTTLED => 'Слишком много попыток. Подождите немного',
            ];
            return response()->json([
                'message' => $messages[$status] ?? 'Не удалось сбросить пароль',
            ], 422);
        }

        return response()->json(['message' => 'Пароль успешно сброшен']);
    }
}
