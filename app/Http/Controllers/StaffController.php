<?php

namespace App\Http\Controllers;

use App\Events\StaffUpdated;
use App\Mail\StaffInviteMail;
use App\Models\Category;
use App\Models\ShopStaff;
use App\Models\User;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class StaffController extends Controller
{
    /**
     * Админ видит и управляет только СВОИМИ сборщиками (role=collector,
     * admin_id = его собственный staff_id) — не всеми сборщиками магазина.
     * Владелец не ограничен. Один приватный скоуп вместо трёх копий одного
     * and-условия (index/resend/destroy).
     */
    private function scopeForActor(\Illuminate\Database\Eloquent\Builder $query, Request $request): \Illuminate\Database\Eloquent\Builder
    {
        if ($request->attributes->get('staff_role') === 'admin') {
            return $query->where('role', 'collector')->where('admin_id', $request->attributes->get('staff_id'));
        }
        return $query;
    }

    /**
     * GET /api/admin/staff
     */
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $staffRows = $this->scopeForActor(ShopStaff::where('shop_id', $shop->id), $request)
            ->with(['user:id,name,email', 'admin:id,invite_name,user_id', 'admin.user:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->get();

        // "Онлайн" — не "входил в последние 5 минут" (last_login_at не
        // отражает выход: logout() ничего в нём не меняет, поэтому вышедший
        // ещё до 5 минут выглядел бы активным). Настоящий источник истины —
        // есть ли у аккаунта хоть один живой Sanctum-токен прямо сейчас:
        // login() всегда сносит старые токены перед выдачей нового
        // (tokens()->delete()), logout() удаляет свой при выходе — значит
        // "нет токена" = точно не в системе. Один запрос на всех, не N+1.
        $userIds = $staffRows->pluck('user_id')->filter()->values();
        $onlineUserIds = $userIds->isEmpty() ? collect() : DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->pluck('tokenable_id')
            ->unique();

        $staff = $staffRows->map(fn($s) => [
            'id'                => $s->id,
            'role'              => $s->role,
            'master_id'         => $s->master_id,
            'admin_id'          => $s->admin_id,
            'admin_name'        => $s->admin ? ($s->admin->user->name ?? $s->admin->invite_name) : null,
            'category_ids'      => $s->category_ids,
            'invite_email'      => $s->invite_email,
            'invite_name'       => $s->invite_name,
            'avatar_url'        => $s->avatar_url,
            'phone'             => $s->phone,
            'last_login_at'     => $s->last_login_at,
            'accepted_at'       => $s->accepted_at,
            'invite_expires_at' => $s->invite_expires_at,
            'is_pending'        => !$s->isAccepted(),
            'is_expired'        => !$s->isAccepted() && $s->invite_expires_at?->isPast(),
            'is_online'         => $s->user_id && $onlineUserIds->contains($s->user_id),
            'user'              => $s->user ? [
                'id'    => $s->user->id,
                'name'  => $s->user->name,
                'email' => $s->user->email,
            ] : null,
        ]);

        return response()->json(['data' => $staff]);
    }

    /**
     * POST /api/admin/staff
     */
    public function store(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'name'           => 'required_if:role,admin,collector|nullable|string|max:255',
            'email'          => 'required|email|max:255',
            'role'           => 'sometimes|in:admin,master,collector',
            'master_id'      => 'required_if:role,master|nullable|uuid',
            'phone'          => 'nullable|string|max:20',
            'category_ids'   => 'sometimes|nullable|array',
            'category_ids.*' => 'uuid',
            'admin_id'       => 'sometimes|nullable|uuid',
        ]);

        $isActingAdmin = $request->attributes->get('staff_role') === 'admin';
        $role  = $data['role'] ?? ($isActingAdmin ? 'collector' : 'admin');
        $email = strtolower(trim($data['email']));
        $name  = isset($data['name']) ? trim($data['name']) : null;

        // Управляющий точки (роль admin) приглашает только сборщиков —
        // владелец решает, кто становится admin/master.
        if ($isActingAdmin && $role !== 'collector') {
            return response()->json(['message' => 'Администратор может приглашать только сборщиков'], 422);
        }

        if ($request->user()->email === $email) {
            return response()->json(['message' => 'Нельзя пригласить себя'], 422);
        }

        $masterId = null;
        if ($role === 'master') {
            $masterId = $data['master_id'];
            $masterExists = DB::table('masters')->where('id', $masterId)->exists();
            if (!$masterExists) {
                return response()->json(['message' => 'Мастер не найден'], 404);
            }
            $alreadyLinked = ShopStaff::where('shop_id', $shop->id)
                ->where('role', 'master')
                ->where('master_id', $masterId)
                ->whereNotNull('accepted_at')
                ->exists();
            if ($alreadyLinked) {
                return response()->json(['message' => 'К этому мастеру уже привязан аккаунт'], 409);
            }
        }

        // Ограничение по категориям имеет смысл только для admin — пустой
        // список/значение не прислали → null (без ограничений, как раньше).
        $categoryIds = null;
        if ($role === 'admin' && !empty($data['category_ids'])) {
            $categoryIds = array_values($data['category_ids']);
            $validCount = Category::whereNull('parent_id')->whereIn('id', $categoryIds)->count();
            if ($validCount !== count($categoryIds)) {
                return response()->json(['message' => 'Одна или несколько категорий не найдены'], 422);
            }
        }

        // Привязка сборщика к администратору — имеет смысл только для role=collector.
        // Администратор, создающий сборщика, привязывает его к себе принудительно
        // (не может выбрать другого админа — он и так ограничен ролью). Владелец
        // выбирает администратора сам (или оставляет null — «подчиняется владельцу»).
        $adminId = null;
        if ($role === 'collector') {
            if ($isActingAdmin) {
                $adminId = $request->attributes->get('staff_id');
            } elseif (!empty($data['admin_id'])) {
                $adminExists = ShopStaff::where('id', $data['admin_id'])
                    ->where('shop_id', $shop->id)
                    ->where('role', 'admin')
                    ->whereNotNull('accepted_at')
                    ->exists();
                if (!$adminExists) {
                    return response()->json(['message' => 'Администратор не найден'], 404);
                }
                $adminId = $data['admin_id'];
            }
        }

        $existingUser = User::where('email', $email)->first();

        // Настоящая причина запрета — не «уже есть магазин» сама по себе, а
        // то, что owner-путь в SetShopFromAuth всегда выигрывает у
        // staff-пути: владелец чужого магазина, приняв это приглашение,
        // всё равно продолжит попадать в свой магазин — приглашение
        // окажется мёртвым.
        if ($existingUser && $existingUser->shop !== null) {
            return response()->json([
                'message' => 'Этот пользователь является владельцем другого магазина и не может быть добавлен',
            ], 422);
        }

        // Один человек — одна точка: сотрудник, уже принявший приглашение в
        // другом магазине, не может стать сотрудником и здесь тоже — иначе
        // при входе в систему staff-путь брал бы первую попавшуюся запись
        // (непредсказуемо, в какой магазин он попадёт).
        if ($existingUser && ShopStaff::where('user_id', $existingUser->id)
                ->whereNotNull('accepted_at')
                ->where('shop_id', '!=', $shop->id)
                ->exists()
        ) {
            return response()->json([
                'message' => 'Этот пользователь уже работает в другом магазине',
            ], 422);
        }

        // Не сужаем скоупом актора: эта проверка должна видеть ЛЮБУЮ
        // существующую/pending запись с этим email в магазине — иначе админ
        // мог бы создать дублирующую запись сборщика на email, который уже
        // занят другим сотрудником (другая роль/чужой администратор).
        $staffQuery = ShopStaff::where('shop_id', $shop->id)
            ->where(function ($q) use ($email, $existingUser) {
                $q->where('invite_email', $email);
                if ($existingUser) {
                    $q->orWhere('user_id', $existingUser->id);
                }
            });

        // Уже активный сотрудник (принял приглашение) — блокируем
        if ((clone $staffQuery)->whereNotNull('accepted_at')->exists()) {
            return response()->json(['message' => 'Этот пользователь уже является сотрудником'], 409);
        }

        // Есть pending-приглашение — переотправляем вместо ошибки. Админу
        // виден и переотправляется только pending чужого(!) приглашения,
        // если оно вообще на этот email — иначе он мог бы и переслать (и
        // тем самым подсмотреть toast с данными) приглашение не своего
        // сборщика/другую роль. Для него это тот же конфликт, что «уже
        // является сотрудником».
        $pendingStaff = (clone $staffQuery)->whereNull('accepted_at')->first();
        if ($pendingStaff && $isActingAdmin
            && !($pendingStaff->role === 'collector' && $pendingStaff->admin_id === $request->attributes->get('staff_id'))
        ) {
            return response()->json(['message' => 'Этот пользователь уже является сотрудником'], 409);
        }
        if ($pendingStaff) {
            $token = bin2hex(random_bytes(32));
            $pendingStaff->update([
                'invite_token'      => $token,
                'invite_expires_at' => now()->addHours(48),
            ]);

            $inviteUrl = rtrim(config('app.frontend_url'), '/') . '/invite/' . $token;

            Mail::to($email)->send(new StaffInviteMail(
                inviteUrl:            $inviteUrl,
                shopName:             $shop->name,
                email:                $email,
                requiresRegistration: $existingUser === null,
                role:                 $pendingStaff->role,
            ));

            return response()->json([
                'message' => 'Приглашение отправлено повторно на ' . $email,
                'data'    => [
                    'id'                => $pendingStaff->id,
                    'invite_email'      => $email,
                    'invite_name'       => $pendingStaff->invite_name,
                    'role'              => $pendingStaff->role,
                    'master_id'         => $pendingStaff->master_id,
                    'admin_id'          => $pendingStaff->admin_id,
                    'category_ids'      => $pendingStaff->category_ids,
                    'is_pending'        => true,
                    'is_expired'        => false,
                    'accepted_at'       => null,
                    'invite_expires_at' => $pendingStaff->fresh()->invite_expires_at,
                    'user'              => null,
                ],
            ]);
        }

        $token = bin2hex(random_bytes(32));

        $staffRecord = ShopStaff::create([
            'shop_id'           => $shop->id,
            'user_id'           => $existingUser?->id,
            'role'              => $role,
            'master_id'         => $masterId,
            'admin_id'          => $adminId,
            'category_ids'      => $categoryIds,
            'invite_email'      => $email,
            'invite_name'       => $name,
            'phone'             => isset($data['phone']) ? trim($data['phone']) : null,
            'invite_token'      => $token,
            'invite_expires_at' => now()->addHours(48),
        ]);

        // Список «Команда» у других открытых сеансов (другой админ/вкладка)
        // должен увидеть нового приглашённого сразу, не только на accept/
        // login/logout, как было раньше — тот же приём, что и там.
        StaffUpdated::dispatch($shop->id);

        $inviteUrl = rtrim(config('app.frontend_url'), '/') . '/invite/' . $token;

        Mail::to($email)->send(new StaffInviteMail(
            inviteUrl:            $inviteUrl,
            shopName:             $shop->name,
            email:                $email,
            requiresRegistration: $existingUser === null,
            role:                 $role,
        ));

        return response()->json([
            'message' => 'Приглашение отправлено на ' . $email,
            'data'    => [
                'id'           => $staffRecord->id,
                'invite_email' => $email,
                'invite_name'  => $name,
                'role'         => $role,
                'master_id'    => $masterId,
                'admin_id'     => $adminId,
                'category_ids' => $categoryIds,
                'is_pending'   => true,
                'is_expired'   => false,
                'accepted_at'  => null,
                'invite_expires_at' => $staffRecord->invite_expires_at,
                'user'         => null,
            ],
        ], 201);
    }

    /**
     * PUT /api/admin/staff/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        // Админ правит только СВОИХ сборщиков (scopeForActor), владелец —
        // любого admin/collector в магазине.
        $query = ShopStaff::where('id', $id)->where('shop_id', $shop->id);
        $staffRecord = $request->attributes->get('staff_role') === 'admin'
            ? $this->scopeForActor($query, $request)->firstOrFail()
            : $query->whereIn('role', ['admin', 'collector'])->firstOrFail();

        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'phone'          => 'nullable|string|max:20',
            'avatar_url'     => 'nullable|url|max:1000',
            'category_ids'   => 'sometimes|nullable|array',
            'category_ids.*' => 'uuid',
            'admin_id'       => 'sometimes|nullable|uuid',
        ]);

        $oldAvatarUrl = $staffRecord->avatar_url;

        $update = [
            'invite_name' => trim($data['name']),
            'phone'       => isset($data['phone']) ? trim($data['phone']) : null,
            'avatar_url'  => $data['avatar_url'] ?? $staffRecord->avatar_url,
        ];

        // Категории назначает только владелец, и только админу — тот же
        // приём, что при создании (см. store()).
        if ($request->attributes->get('staff_role') === 'owner' && $staffRecord->role === 'admin' && $request->has('category_ids')) {
            $categoryIds = empty($data['category_ids']) ? null : array_values($data['category_ids']);
            if ($categoryIds !== null) {
                $validCount = Category::whereNull('parent_id')->whereIn('id', $categoryIds)->count();
                if ($validCount !== count($categoryIds)) {
                    return response()->json(['message' => 'Одна или несколько категорий не найдены'], 422);
                }
            }
            $update['category_ids'] = $categoryIds;
        }

        // Администратора у сборщика меняет только владелец — тот же приём,
        // что для category_ids выше. null = «подчиняется владельцу».
        if ($request->attributes->get('staff_role') === 'owner' && $staffRecord->role === 'collector' && $request->has('admin_id')) {
            $adminId = $data['admin_id'] ?? null;
            if ($adminId !== null) {
                $adminExists = ShopStaff::where('id', $adminId)
                    ->where('shop_id', $shop->id)
                    ->where('role', 'admin')
                    ->whereNotNull('accepted_at')
                    ->exists();
                if (!$adminExists) {
                    return response()->json(['message' => 'Администратор не найден'], 404);
                }
            }
            $update['admin_id'] = $adminId;
        }

        $staffRecord->update($update);

        if (array_key_exists('avatar_url', $data) && $data['avatar_url'] !== $oldAvatarUrl) {
            StorageService::deleteByUrl($oldAvatarUrl);
        }

        return response()->json(['message' => 'Данные обновлены']);
    }

    /**
     * POST /api/admin/staff/{id}/resend
     */
    public function resend(Request $request, string $id): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $staffRecord = $this->scopeForActor(ShopStaff::where('id', $id)->where('shop_id', $shop->id), $request)
            ->whereNull('accepted_at')
            ->firstOrFail();

        $token = bin2hex(random_bytes(32));

        $staffRecord->update([
            'invite_token'      => $token,
            'invite_expires_at' => now()->addHours(48),
        ]);

        $inviteUrl = rtrim(config('app.frontend_url'), '/') . '/invite/' . $token;

        $existingUser = $staffRecord->user_id ? User::find($staffRecord->user_id) : null;

        Mail::to($staffRecord->invite_email)->send(new StaffInviteMail(
            inviteUrl:            $inviteUrl,
            shopName:             $shop->name,
            email:                $staffRecord->invite_email,
            requiresRegistration: $existingUser === null,
            role:                 $staffRecord->role,
        ));

        return response()->json(['message' => 'Приглашение отправлено повторно']);
    }

    /**
     * DELETE /api/admin/staff/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $staffRecord = $this->scopeForActor(ShopStaff::where('id', $id)->where('shop_id', $shop->id), $request)
            ->firstOrFail();

        // Администратора со своими сборщиками нельзя удалить, пока у него
        // есть привязанные сборщики — иначе они молча остаются без
        // ограничения по категориям (см. SetShopFromAuth). FK (ON DELETE
        // RESTRICT) не даст это сделать и на уровне БД — тут просто понятное
        // сообщение вместо 500.
        if ($staffRecord->role === 'admin' && $staffRecord->collectors()->exists()) {
            return response()->json([
                'message' => 'Сначала переведите сборщиков этого администратора к другому администратору или удалите их',
            ], 422);
        }

        if ($staffRecord->user_id && $staffRecord->accepted_at) {
            User::find($staffRecord->user_id)?->tokens()->delete();
        }

        $avatarUrl = $staffRecord->avatar_url;
        $staffRecord->delete();
        StorageService::deleteByUrl($avatarUrl);

        StaffUpdated::dispatch($shop->id);

        return response()->json(['message' => 'Доступ отозван']);
    }
}
