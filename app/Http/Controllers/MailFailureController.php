<?php

namespace App\Http\Controllers;

use App\Models\MailFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Несработавшие письма — видно только владельцу магазина (роут защищён middleware
 * 'owner'), Настройки → Уведомления. pendingCount()/markSeen() — тот же паттерн, что
 * ReviewController::pendingCount/markSeen, только курсор — shops.mail_failures_last_seen_at.
 */
class MailFailureController extends Controller
{
    /**
     * GET /admin/mail-failures — последние несработавшие письма магазина.
     */
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $failures = MailFailure::forShop($shop->id)
            ->latest('created_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $failures]);
    }

    /**
     * GET /admin/mail-failures/pending-count — сколько новых с прошлого визита на вкладку.
     */
    public function pendingCount(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $count = MailFailure::forShop($shop->id)
            ->when(
                $shop->mail_failures_last_seen_at,
                fn ($q) => $q->where('created_at', '>', $shop->mail_failures_last_seen_at),
            )
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * POST /admin/mail-failures/mark-seen — фиксирует «я только что смотрел список».
     */
    public function markSeen(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $shop->update(['mail_failures_last_seen_at' => now()]);

        return response()->json(['message' => 'ok']);
    }
}
