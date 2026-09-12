<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperadminOwnerController extends Controller
{
    // GET /api/superadmin/owners
    // Только владельцы хотя бы одного магазина — владелец сети без единой
    // точки сюда не попадёт, но такого сценария у нас нет: признак сети
    // включают уже зарегистрированному (через обычный /auth/register)
    // клиенту с его первой точкой.
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->whereHas('shops')
            ->withCount('shops')
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $owners = $query->paginate(25)->through(fn (User $u) => [
            'id'             => $u->id,
            'name'           => $u->name,
            'email'          => $u->email,
            'created_at'     => $u->created_at,
            'shops_count'    => $u->shops_count,
            'is_chain_owner' => (bool) $u->is_chain_owner,
        ]);

        return response()->json($owners);
    }

    // PUT /api/superadmin/owners/{user}/chain
    public function toggleChainOwner(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = $data['enabled'];

        // Снять признак у владельца с несколькими точками — можно: панель
        // сети исчезнет, точки останутся, ShopAccess::defaultFor отдаёт ему
        // самую старую детерминированно (см. App\Support\ShopAccess) —
        // человек не остаётся без доступа. Предупреждение об этом — на
        // фронте суперадмина, не хардблок здесь.
        $user->forceFill(['is_chain_owner' => $enabled])->save();

        DB::table('user_flag_audit')->insert([
            'user_id'       => $user->id,
            'actor_user_id' => $request->user()->id,
            'flag_key'      => 'is_chain_owner',
            'enabled'       => $enabled,
        ]);

        return response()->json([
            'id'             => $user->id,
            'is_chain_owner' => (bool) $user->is_chain_owner,
        ]);
    }
}
