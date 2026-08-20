<?php

namespace App\Http\Controllers;

use App\Mail\StaffInviteMail;
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
     * GET /api/admin/staff
     */
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $staff = ShopStaff::where('shop_id', $shop->id)
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($s) => [
                'id'                => $s->id,
                'role'              => $s->role,
                'master_id'         => $s->master_id,
                'invite_email'      => $s->invite_email,
                'invite_name'       => $s->invite_name,
                'avatar_url'        => $s->avatar_url,
                'phone'             => $s->phone,
                'last_login_at'     => $s->last_login_at,
                'accepted_at'       => $s->accepted_at,
                'invite_expires_at' => $s->invite_expires_at,
                'is_pending'        => !$s->isAccepted(),
                'is_expired'        => !$s->isAccepted() && $s->invite_expires_at?->isPast(),
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
            'name'      => 'required_if:role,admin|nullable|string|max:255',
            'email'     => 'required|email|max:255',
            'role'      => 'sometimes|in:admin,master',
            'master_id' => 'required_if:role,master|nullable|uuid',
            'phone'     => 'nullable|string|max:20',
        ]);

        $role  = $data['role'] ?? 'admin';
        $email = strtolower(trim($data['email']));
        $name  = isset($data['name']) ? trim($data['name']) : null;

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

        $existingUser = User::where('email', $email)->first();

        if ($existingUser && $existingUser->shop) {
            return response()->json([
                'message' => 'Этот пользователь является владельцем другого магазина и не может быть добавлен',
            ], 422);
        }

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

        // Есть pending-приглашение — переотправляем вместо ошибки
        $pendingStaff = (clone $staffQuery)->whereNull('accepted_at')->first();
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
            ));

            return response()->json([
                'message' => 'Приглашение отправлено повторно на ' . $email,
                'data'    => [
                    'id'                => $pendingStaff->id,
                    'invite_email'      => $email,
                    'invite_name'       => $pendingStaff->invite_name,
                    'role'              => $pendingStaff->role,
                    'master_id'         => $pendingStaff->master_id,
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
            'invite_email'      => $email,
            'invite_name'       => $name,
            'phone'             => isset($data['phone']) ? trim($data['phone']) : null,
            'invite_token'      => $token,
            'invite_expires_at' => now()->addHours(48),
        ]);

        $inviteUrl = rtrim(config('app.frontend_url'), '/') . '/invite/' . $token;

        Mail::to($email)->send(new StaffInviteMail(
            inviteUrl:            $inviteUrl,
            shopName:             $shop->name,
            email:                $email,
            requiresRegistration: $existingUser === null,
        ));

        return response()->json([
            'message' => 'Приглашение отправлено на ' . $email,
            'data'    => [
                'id'           => $staffRecord->id,
                'invite_email' => $email,
                'invite_name'  => $name,
                'role'         => $role,
                'master_id'    => $masterId,
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

        $staffRecord = ShopStaff::where('id', $id)
            ->where('shop_id', $shop->id)
            ->where('role', 'admin')
            ->firstOrFail();

        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'phone'      => 'nullable|string|max:20',
            'avatar_url' => 'nullable|url|max:1000',
        ]);

        $oldAvatarUrl = $staffRecord->avatar_url;

        $staffRecord->update([
            'invite_name' => trim($data['name']),
            'phone'       => isset($data['phone']) ? trim($data['phone']) : null,
            'avatar_url'  => $data['avatar_url'] ?? $staffRecord->avatar_url,
        ]);

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

        $staffRecord = ShopStaff::where('id', $id)
            ->where('shop_id', $shop->id)
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
        ));

        return response()->json(['message' => 'Приглашение отправлено повторно']);
    }

    /**
     * DELETE /api/admin/staff/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $staffRecord = ShopStaff::where('id', $id)
            ->where('shop_id', $shop->id)
            ->firstOrFail();

        if ($staffRecord->user_id && $staffRecord->accepted_at) {
            User::find($staffRecord->user_id)?->tokens()->delete();
        }

        $avatarUrl = $staffRecord->avatar_url;
        $staffRecord->delete();
        StorageService::deleteByUrl($avatarUrl);

        return response()->json(['message' => 'Доступ отозван']);
    }
}
