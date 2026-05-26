<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Master;
use App\Models\ShopStaff;
use App\Services\MaxService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterPortalController extends Controller
{
    /**
     * GET /api/master/messenger-status
     */
    public function messengerStatus(Request $request): JsonResponse
    {
        $staff = $this->getStaff($request);

        return response()->json([
            'telegram_connected' => $staff->telegram_chat_id !== null,
            'max_connected'      => $staff->max_user_id !== null,
        ]);
    }

    /**
     * GET /api/master/telegram-link
     * Returns a deep-link URL to connect master's Telegram.
     */
    public function generateTelegramLink(Request $request): JsonResponse
    {
        $staff = $this->getStaff($request);
        $url   = TelegramService::generateMasterLinkToken($staff);

        return response()->json([
            'url'          => $url,
            'bot_username' => config('services.telegram.bot_username'),
        ]);
    }

    /**
     * POST /api/master/max-code
     * Returns a 6-char code for connecting MAX.
     */
    public function generateMaxCode(Request $request): JsonResponse
    {
        $staff = $this->getStaff($request);
        $code  = MaxService::generateMasterCode($staff);

        return response()->json([
            'code'               => $code,
            'expires_in_minutes' => 10,
            'bot_username'       => config('services.max.bot_username'),
        ]);
    }

    /**
     * DELETE /api/master/telegram
     */
    public function disconnectTelegram(Request $request): JsonResponse
    {
        $staff = $this->getStaff($request);
        $staff->update(['telegram_chat_id' => null]);

        return response()->json(['message' => 'Telegram отключён']);
    }

    /**
     * DELETE /api/master/max
     */
    public function disconnectMax(Request $request): JsonResponse
    {
        $staff = $this->getStaff($request);
        $staff->update(['max_user_id' => null]);

        return response()->json(['message' => 'MAX отключён']);
    }

    /**
     * GET /api/master/stats?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function stats(Request $request): JsonResponse
    {
        $masterId = $request->attributes->get('staff_master_id');
        if (!$masterId) {
            return response()->json(['message' => 'Аккаунт не привязан к мастеру'], 403);
        }

        $shop = $request->attributes->get('shop');
        $tz   = $shop?->timezone ?? 'Europe/Moscow';

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'), $tz)->startOfDay()->utc()
            : Carbon::now($tz)->startOfMonth()->startOfDay()->utc();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'), $tz)->endOfDay()->utc()
            : Carbon::now($tz)->endOfDay()->utc();

        $master = Master::findOrFail($masterId);

        $bookings = Booking::where('master_id', $masterId)
            ->whereBetween('start_time', [$from, $to])
            ->with('service:id,name,price')
            ->orderBy('start_time', 'desc')
            ->get();

        $completed = $bookings->where('status', 'completed');
        $noShow    = $bookings->where('status', 'no_show')->count();

        // Earnings calculation
        $earnings = 0.0;
        if ($master->salary_rate > 0) {
            if ($master->salary_type === 'fixed') {
                $earnings = $completed->count() * (float) $master->salary_rate;
            } else {
                foreach ($completed as $booking) {
                    $price    = (int) ($booking->service?->price ?? 0);
                    $earnings += $price * (float) $master->salary_rate / 100;
                }
            }
        }

        // Completed bookings list with individual amounts
        $completedList = $completed->map(function (Booking $b) use ($master) {
            $price  = (int) ($b->service?->price ?? 0);
            $amount = 0.0;
            if ($master->salary_rate > 0) {
                $amount = $master->salary_type === 'fixed'
                    ? (float) $master->salary_rate
                    : $price * (float) $master->salary_rate / 100;
            }
            return [
                'id'            => $b->id,
                'start_time'    => $b->start_time,
                'customer_name' => $b->customer_name,
                'service_name'  => $b->service?->name ?? '—',
                'service_price' => $price,
                'amount'        => round($amount, 2),
            ];
        })->values();

        return response()->json([
            'period'    => ['from' => $from->setTimezone($tz)->toDateString(), 'to' => $to->setTimezone($tz)->toDateString()],
            'total'     => $bookings->count(),
            'completed' => $completed->count(),
            'no_show'   => $noShow,
            'earnings'  => round($earnings, 2),
            'salary_type' => $master->salary_type,
            'salary_rate' => (float) $master->salary_rate,
            'bookings'  => $completedList,
        ]);
    }

    private function getStaff(Request $request): ShopStaff
    {
        $userId = auth()->id();
        $shopId = $request->attributes->get('shop')?->id;

        return ShopStaff::where('user_id', $userId)
            ->where('shop_id', $shopId)
            ->where('role', 'master')
            ->whereNotNull('accepted_at')
            ->firstOrFail();
    }
}
