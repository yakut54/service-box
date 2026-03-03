<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Mail\ResetPasswordMail;
use App\Models\Shop;
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
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $schemaName = 'shop_' . strtolower(Str::random(12));

            $shop = Shop::create([
                'user_id' => $user->id,
                'name' => $request->shop_name,
                'domain' => $request->shop_domain,
                'schema_name' => $schemaName,
                'subscription_plan' => 'micro',
                'timezone' => $request->timezone ?? 'Europe/Moscow',
            ]);

            TenantService::createSchema($schemaName);

            $token = $user->createToken('auth_token')->plainTextToken;

            DB::commit();

            return response()->json([
                'message' => 'Registration successful',
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
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
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
                'message' => 'Invalid credentials',
            ], 401);
        }

        $user = Auth::user();
        $shop = $user->shop;

        if (!$shop) {
            return response()->json([
                'message' => 'Shop not found for this user',
            ], 404);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
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
                'subscription_expires_at' => $shop->subscription_expires_at,
                'widget_config' => $shop->widget_config,
                'work_start' => $shop->work_start,
                'work_end' => $shop->work_end,
                'slot_duration' => $shop->slot_duration,
                'timezone' => $shop->timezone,
                'telegram_bot_connected' => $shop->telegram_bot_connected,
                'yookassa_shop_id' => $shop->yookassa_shop_id,
            ],
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
            'message' => 'Logout successful',
        ]);
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $shop = $user->shop;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'shop' => [
                'id' => $shop->id,
                'name' => $shop->name,
                'domain' => $shop->domain,
                'api_key' => $shop->api_key,
                'subscription_plan' => $shop->subscription_plan,
                'subscription_expires_at' => $shop->subscription_expires_at,
                'widget_config' => $shop->widget_config,
                'work_start' => $shop->work_start,
                'work_end' => $shop->work_end,
                'slot_duration' => $shop->slot_duration,
                'timezone' => $shop->timezone,
                'telegram_bot_connected' => $shop->telegram_bot_connected,
                'yookassa_shop_id' => $shop->yookassa_shop_id,
            ],
        ]);
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
            'message' => 'Token refreshed',
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

        return response()->json(['message' => 'Пароль успешно изменён']);
    }

    /**
     * POST /api/auth/forgot-password  (public)
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        // Не раскрываем информацию о существовании аккаунта
        if ($user) {
            $token    = Password::createToken($user);
            $resetUrl = rtrim(config('app.frontend_url'), '/') . '/reset-password'
                . '?token=' . $token
                . '&email=' . urlencode($user->email);

            Mail::to($user->email)->send(new ResetPasswordMail($resetUrl, $user->email));
        }

        return response()->json(['message' => 'Если аккаунт с таким email существует, письмо отправлено']);
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
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'Пароль успешно сброшен']);
    }
}
