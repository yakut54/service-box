<?php

namespace App\Http\Controllers;

use App\Events\UserSessionSuperseded;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Mail\ResetPasswordMail;
use App\Models\Shop;
use App\Models\ShopStaff;
use App\Models\User;
use App\Services\StorageService;
use App\Support\ShopAccess;
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

            // schema_name/api_key/widget_config и создание тенантной схемы —
            // всё в Shop::boot() (creating/created). Раньше эта же схема
            // создавалась ещё раз вручную через TenantService::createSchema()
            // сразу после Shop::create(), а create_shop_schema() начинается с
            // DROP SCHEMA IF EXISTS ... CASCADE — то есть свежесозданная схема
            // сносилась и создавалась заново вторым вызовом.
            $shop = Shop::create([
                'user_id' => $user->id,
                'name' => $request->shop_name,
                'domain' => $request->shop_domain,
                'timezone' => $request->timezone ?? 'Europe/Moscow',
            ]);

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

        // Свежий вход всегда ведёт в панель сети — заголовок X-Acting-Shop-Id
        // тут сознательно не читаем (владелец сети сам выберет точку внутри).
        $ctx = ShopAccess::defaultFor($user);

        // is_superadmin — отдельный аккаунт управления платформой, не шопер;
        // магазина у него может не быть вообще (см. Superadmin\*, роуты вне
        // auth.shop). Владелец сети — тот же принцип, уже был.
        if (!$ctx && !$user->is_chain_owner && !$user->is_superadmin) {
            return response()->json([
                'message' => 'Магазин не найден',
            ], 404);
        }

        $shop = $ctx['shop'] ?? null;

        // Уже открытая вкладка на другом устройстве узнаёт об этом входе в
        // реальном времени и покажет предупреждение до того, как её токен
        // реально удалят строкой ниже — WS-соединение не привязано к
        // валидности токена, которым его когда-то авторизовали. У владельца
        // сети без выбранной точки считаем по всем его магазинам разом.
        $shopIds = $shop ? [$shop->id] : $user->shops()->pluck('id')->all();
        $totalUsers = 1 + ShopStaff::whereIn('shop_id', $shopIds)
            ->whereNotNull('accepted_at')
            ->count();
        UserSessionSuperseded::dispatch((string) $user->id, $request->ip(), $totalUsers);

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Вход выполнен',
            'user' => $this->userPayload($user, $ctx),
            'shop' => $shop ? $this->shopPayload($shop) : null,
            'chain' => $this->chainPayload($user),
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

        [$ctx, $error] = $this->resolveActingContext($request, $user);
        if ($error) {
            return $error;
        }

        if (!$ctx && !$user->is_chain_owner && !$user->is_superadmin) {
            return response()->json(['message' => 'Магазин не найден'], 404);
        }

        $shop = $ctx['shop'] ?? null;

        return response()->json([
            'user' => $this->userPayload($user, $ctx),
            'shop' => $shop ? array_merge($this->shopPayload($shop), [
                'domain'             => $shop->domain,
                'min_booking_notice' => $shop->min_booking_notice,
                'prepayment_enabled' => (bool) $shop->prepayment_enabled,
                'prepayment_amount'  => (int) $shop->prepayment_amount,
                'delivery_settings'  => $shop->delivery_settings,
            ]) : null,
            'chain' => $this->chainPayload($user),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * X-Acting-Shop-Id для эндпоинтов вне группы /api/admin/* (auth.shop туда
     * не подключён, поэтому тут своя проверка — те же правила, что в
     * SetShopFromAuth: владение/членство проверяется заново, заголовку самому
     * по себе не доверяем).
     *
     * @return array{0: ?array, 1: ?JsonResponse}
     */
    private function resolveActingContext(Request $request, User $user): array
    {
        $actingShopId = $request->header('X-Acting-Shop-Id');

        if ($actingShopId === null || $actingShopId === '') {
            return [ShopAccess::defaultFor($user), null];
        }

        if (!Str::isUuid($actingShopId)) {
            return [null, response()->json([
                'message' => 'Некорректный магазин',
                'code'    => 'acting_shop_invalid',
            ], 400)];
        }

        $ctx = ShopAccess::forShop($user, $actingShopId);

        if (!$ctx) {
            return [null, response()->json([
                'message' => 'Нет доступа к этому магазину',
                'code'    => 'acting_shop_forbidden',
            ], 403)];
        }

        return [$ctx, null];
    }

    /** @param ?array{shop: Shop, role: string, staff: mixed} $ctx */
    private function userPayload(User $user, ?array $ctx): array
    {
        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'avatar_url'     => $user->avatar_url,
            'phone'          => $user->phone,
            'is_superadmin'  => (bool) $user->is_superadmin,
            'is_chain_owner' => (bool) $user->is_chain_owner,
            'role'           => $ctx['role'] ?? match (true) {
                $user->is_chain_owner => 'chain_owner',
                $user->is_superadmin  => 'superadmin',
                default               => null,
            },
        ];
    }

    /** Только для владельца сети — сколько у него точек. */
    private function chainPayload(User $user): ?array
    {
        if (!$user->is_chain_owner) {
            return null;
        }

        return [
            'shops_count' => $user->shops()->count(),
            'name'        => $user->chain_name,
        ];
    }

    private function shopPayload(Shop $shop): array
    {
        return [
            'id'                     => $shop->id,
            'name'                   => $shop->name,
            'api_key'                => $shop->api_key,
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
            'chat_customer_delete_enabled' => (bool) $shop->chat_customer_delete_enabled,
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
     * PUT /api/auth/profile  (requires auth)
     *
     * Имя/аватар/телефон владельца магазина — email сюда сознательно не
     * принимается (он же логин, менять его отдельная задача с подтверждением,
     * см. StaffController::update, где то же самое для сотрудника).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'phone'      => 'nullable|string|max:20',
            'avatar_url' => 'nullable|url|max:1000',
        ]);

        $user->name  = trim($data['name']);
        $user->phone = isset($data['phone']) ? trim($data['phone']) : null;

        // avatar_url трогаем ТОЛЬКО если ключ реально прислали и ссылка
        // изменилась. null = «убрать аватар». Старый раскладка была битой:
        // `$data['avatar_url'] ?? $user->avatar_url` при null оставлял ссылку в
        // БД, а условие ниже всё равно удаляло файл — в итоге БД показывала
        // мёртвую ссылку (та же болячка, что была у логотипа магазина).
        if ($request->has('avatar_url')) {
            $newAvatarUrl = $data['avatar_url'] ?? null;
            if ($newAvatarUrl !== $user->avatar_url) {
                StorageService::deleteByUrl($user->avatar_url);
                $user->avatar_url = $newAvatarUrl;
            }
        }

        $user->save();

        [$ctx, $error] = $this->resolveActingContext($request, $user);
        if ($error) {
            return $error;
        }

        return response()->json([
            'user' => $this->userPayload($user, $ctx),
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
