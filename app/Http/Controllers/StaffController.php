<?php

namespace App\Http\Controllers;

use App\Mail\StaffInviteMail;
use App\Models\ShopStaff;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class StaffController extends Controller
{
    /**
     * GET /api/admin/staff
     */
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        if (!$shop->hasFeature('staff_management')) {
            return response()->json([
                'message' => 'Управление командой недоступно на вашем тарифе. Перейдите на Business или Pro.',
                'upgrade_required' => true,
            ], 403);
        }

        $staff = ShopStaff::where('shop_id', $shop->id)
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($s) => [
                'id'                => $s->id,
                'role'              => $s->role,
                'invite_email'      => $s->invite_email,
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

        if (!$shop->hasFeature('staff_management')) {
            return response()->json([
                'message' => 'Управление командой недоступно на вашем тарифе. Перейдите на Business или Pro.',
                'upgrade_required' => true,
            ], 403);
        }

        $data = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = strtolower(trim($data['email']));

        // Нельзя пригласить самого себя (владельца магазина)
        if ($request->user()->email === $email) {
            return response()->json(['message' => 'Нельзя пригласить себя'], 422);
        }

        // Проверяем: уже сотрудник или уже приглашён
        $existingUser = User::where('email', $email)->first();

        // Нельзя пригласить того, кто уже владеет своим магазином
        if ($existingUser && $existingUser->shop) {
            return response()->json([
                'message' => 'Этот пользователь является владельцем другого магазина и не может быть добавлен как администратор',
            ], 422);
        }

        $alreadyStaff = ShopStaff::where('shop_id', $shop->id)
            ->where(function ($q) use ($email, $existingUser) {
                $q->where('invite_email', $email);
                if ($existingUser) {
                    $q->orWhere('user_id', $existingUser->id);
                }
            })
            ->exists();

        if ($alreadyStaff) {
            return response()->json(['message' => 'Этот пользователь уже является сотрудником или уже приглашён'], 409);
        }

        // Безопасный случайный токен (64 hex-символа = 256 бит энтропии)
        $token = bin2hex(random_bytes(32));

        $staffRecord = ShopStaff::create([
            'shop_id'            => $shop->id,
            'user_id'            => $existingUser?->id,
            'role'               => 'admin',
            'invite_email'       => $email,
            'invite_token'       => $token,
            'invite_expires_at'  => now()->addHours(48),
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
                'role'         => 'admin',
            ],
        ], 201);
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

        // Инвалидируем все токены сотрудника немедленно
        if ($staffRecord->user_id && $staffRecord->accepted_at) {
            User::find($staffRecord->user_id)?->tokens()->delete();
        }

        $staffRecord->delete();

        return response()->json(['message' => 'Доступ отозван']);
    }
}
