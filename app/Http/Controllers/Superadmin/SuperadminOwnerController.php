<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOwnerRequest;
use App\Mail\AdminAccountCreatedMail;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class SuperadminOwnerController extends Controller
{
    // GET /api/superadmin/owners
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->whereHas('shop')
            ->with('shop:id,user_id,name')
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $owners = $query->paginate(25)->through(fn (User $u) => [
            'id'         => $u->id,
            'name'       => $u->name,
            'email'      => $u->email,
            'created_at' => $u->created_at,
            'shop_name'  => $u->shop?->name,
        ]);

        return response()->json($owners);
    }

    /**
     * POST /api/superadmin/owners
     *
     * Заводит нового админа сразу с его точкой — без ожидания саморегистрации
     * через /auth/register. Пароль — случайный и непригодный для входа,
     * админ задаёт свой через ту же ссылку, что и обычный сброс пароля
     * (см. AuthController::forgotPassword) — письмо только с другим текстом.
     */
    public function store(CreateOwnerRequest $request): JsonResponse
    {
        [$user, $shop] = DB::transaction(function () use ($request) {
            $user = User::create([
                'name'     => $request->validated('name'),
                'email'    => $request->validated('email'),
                'password' => Hash::make(Str::random(40)),
            ]);

            // schema_name/api_key/widget_config и создание тенантной схемы —
            // всё в Shop::boot() (см. комментарий в AuthController::register
            // про двойное создание схемы) — тут просто Shop::create().
            $shop = Shop::create([
                'user_id'  => $user->id,
                'name'     => $request->validated('shop_name'),
                'domain'   => $request->validated('shop_domain'),
                'timezone' => $request->validated('timezone') ?? 'Europe/Moscow',
            ]);

            DB::table('user_flag_audit')->insert([
                'user_id'       => $user->id,
                'actor_user_id' => $request->user()->id,
                'flag_key'      => 'admin_created',
                'enabled'       => true,
            ]);

            return [$user, $shop];
        });

        $token    = Password::createToken($user);
        $resetUrl = rtrim(config('app.frontend_url'), '/') . '/reset-password'
            . '?token=' . $token
            . '&email=' . urlencode($user->email);

        Mail::to($user->email)->send(new AdminAccountCreatedMail($resetUrl, $user->email, $shop->name));

        return response()->json([
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'created_at' => $user->created_at,
            'shop_name'  => $shop->name,
        ], 201);
    }
}
