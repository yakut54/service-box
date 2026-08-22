<?php

namespace App\Http\Controllers;

use App\Models\ShopStaff;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InviteController extends Controller
{
    /**
     * GET /api/invite/{token}
     * Валидация токена — возвращает имя магазина и нужна ли регистрация.
     */
    public function show(string $token): JsonResponse
    {
        $staff = $this->findValidStaff($token);

        if (!$staff) {
            return response()->json([
                'message' => 'Приглашение недействительно или ссылка устарела',
            ], 404);
        }

        $masterName = null;
        if ($staff->role === 'master' && $staff->master_id) {
            \App\Services\TenantService::setContext($staff->shop);
            $master = DB::table('masters')->where('id', $staff->master_id)->first();
            $masterName = $master?->name;
            \App\Services\TenantService::resetContext();
        }

        return response()->json([
            'shop_name'             => $staff->shop->name,
            'email'                 => $staff->invite_email,
            'role'                  => $staff->role,
            'master_name'           => $masterName,
            'requires_registration' => $staff->user_id === null,
        ]);
    }

    /**
     * POST /api/invite/accept
     * Принять приглашение. Для новых пользователей — имя и пароль обязательны.
     */
    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => 'required|string',
            'name'     => 'sometimes|string|max:255',
            'password' => 'sometimes|string|min:8',
        ]);

        $staff = $this->findValidStaff($data['token']);

        if (!$staff) {
            return response()->json([
                'message' => 'Приглашение недействительно или ссылка устарела',
            ], 404);
        }

        if ($staff->accepted_at) {
            return response()->json([
                'message' => 'Это приглашение уже было принято',
            ], 409);
        }

        DB::beginTransaction();
        try {
            if ($staff->user_id === null) {
                // Новый пользователь — нужны имя и пароль
                $request->validate([
                    'name'     => 'required|string|max:255',
                    'password' => 'required|string|min:8',
                ]);

                // Гонка: приглашение создавалось, когда пользователя с этим
                // email ещё не было (иначе store() сразу проставил бы
                // user_id) — но кто-то мог зарегистрироваться на этот email
                // за время до принятия (у нас нет подтверждения email при
                // регистрации, /auth/register создаёт пользователя сразу).
                //
                // Раньше в этом случае найденный аккаунт молча переиспользовался,
                // а введённый здесь пароль просто отбрасывался — если аккаунт
                // создал не настоящий приглашённый, а тот, кто узнал про
                // приглашение и успел зарегистрироваться первым на его email,
                // он получал доступ к магазину под своим паролем (уязвимость
                // найдена 2026-08-22). /auth/register всегда создаёт вместе
                // с пользователем его собственный магазин — тот же признак,
                // которым store() и раньше блокировал приглашение владельца
                // чужого магазина, здесь проверяем повторно на момент
                // принятия и отклоняем ровно тот случай, где self-service
                // регистрация вообще возможна.
                $existingUser = User::where('email', $staff->invite_email)->first();

                if ($existingUser && $existingUser->shop) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'На этот email уже зарегистрирован аккаунт владельца магазина. '
                            . 'Попросите отправителя приглашения выслать его заново.',
                    ], 409);
                }

                $user = $existingUser ?? User::create([
                    'name'     => $data['name'],
                    'email'    => $staff->invite_email,
                    'password' => Hash::make($data['password']),
                ]);

                $staff->user_id = $user->id;
            } else {
                $user = User::findOrFail($staff->user_id);
            }

            // Принять приглашение и аннулировать токен (одноразовый)
            $staff->accepted_at  = now();
            $staff->invite_token = null;
            $staff->save();

            // Для мастера — привязать user_id к записи мастера в tenant-схеме
            if ($staff->role === 'master' && $staff->master_id) {
                \App\Services\TenantService::setContext($staff->shop);
                \Illuminate\Support\Facades\DB::table('masters')
                    ->where('id', $staff->master_id)
                    ->update(['user_id' => $user->id]);
                \App\Services\TenantService::resetContext();
            }

            DB::commit();

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Добро пожаловать в команду!',
                'token'   => $token,
                'user'    => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'role'  => $staff->role,
                ],
                'shop' => [
                    'id'   => $staff->shop->id,
                    'name' => $staff->shop->name,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Ошибка при принятии приглашения'], 500);
        }
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function findValidStaff(string $token): ?ShopStaff
    {
        // Поиск по токену + дополнительная проверка через hash_equals
        // для защиты от timing attacks
        $staff = ShopStaff::where('invite_token', $token)
            ->whereNull('accepted_at')
            ->where('invite_expires_at', '>', now())
            ->with('shop')
            ->first();

        if (!$staff) {
            return null;
        }

        // Constant-time comparison — защита от timing attack
        if (!hash_equals($staff->invite_token, $token)) {
            return null;
        }

        return $staff;
    }
}
