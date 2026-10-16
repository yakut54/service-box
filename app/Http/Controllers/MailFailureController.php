<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\TracksSeenCursor;
use App\Models\MailFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Несработавшие письма — видно только владельцу магазина (роут защищён middleware
 * 'owner'), Настройки → Уведомления. pendingCount()/markSeen() — тот же паттерн, что
 * ReviewController::pendingCount/markSeen, курсор — персонально для пользователя
 * (cursor_key='mail_failures', см. TracksSeenCursor).
 *
 * "Почта вообще не настроена" (config('mail.default') === 'log') — НЕ пишется в
 * mail_failures и не завязана на курсор "просмотрено". Раньше это ловила отдельная
 * суточная команда, но раз в сутки — значит после любой ручной уборки тестовых данных
 * или просто "ещё не успела сработать" индикатор врал зелёным до суток. Теперь это
 * живая проверка на каждый запрос: не может быть "почта не настроена, но горит
 * зелёным" — драйвер либо `log`, либо нет, прямо сейчас.
 */
class MailFailureController extends Controller
{
    use TracksSeenCursor;

    /**
     * GET /admin/mail-failures — последние несработавшие письма магазина +
     * живой статус "настроена ли вообще почта".
     */
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $failures = MailFailure::forShop($shop->id)
            ->latest('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $failures,
            'mail_configured' => self::mailConfigured(),
        ]);
    }

    /**
     * GET /admin/mail-failures/pending-count — сколько новых с прошлого визита на
     * вкладку + 1, если почта прямо сейчас не настроена (эта единица никуда не
     * денется, пока не настроят — mark-seen её не гасит, специально).
     */
    public function pendingCount(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $count = $this->pendingCountSince($request, 'mail_failures', MailFailure::forShop($shop->id));

        if (!self::mailConfigured()) {
            $count++;
        }

        return response()->json(['count' => $count]);
    }

    /**
     * POST /admin/mail-failures/mark-seen — фиксирует «я только что смотрел список».
     * На живую проверку "настроена ли почта" не влияет — её нельзя отметить прочитанной.
     */
    public function markSeen(Request $request): JsonResponse
    {
        $this->markSeenNow($request, 'mail_failures');

        return response()->json(['message' => 'ok']);
    }

    private static function mailConfigured(): bool
    {
        return config('mail.default') !== 'log';
    }
}
