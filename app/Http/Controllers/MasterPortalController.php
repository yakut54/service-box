<?php

namespace App\Http\Controllers;

use App\Models\ShopStaff;
use App\Services\MaxService;
use App\Services\TelegramService;
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
