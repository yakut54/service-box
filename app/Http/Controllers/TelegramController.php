<?php

namespace App\Http\Controllers;

use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    /**
     * Generate connection code
     *
     * POST /api/admin/telegram/generate-code
     */
    public function generateCode(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $code = TelegramService::generateConnectionCode($shop);

        return response()->json([
            'code' => $code,
            'expires_in_minutes' => 10,
            'bot_username' => config('services.telegram.bot_username'),
        ]);
    }

    /**
     * Get Telegram connection status
     *
     * GET /api/admin/telegram/status
     */
    public function status(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        return response()->json([
            'connected' => (bool) $shop->telegram_bot_connected,
            'chat_id' => $shop->telegram_chat_id,
            'bot_username' => config('services.telegram.bot_username'),
        ]);
    }

    /**
     * Disconnect Telegram
     *
     * POST /api/admin/telegram/disconnect
     */
    public function disconnect(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $shop->update([
            'telegram_chat_id' => null,
            'telegram_bot_connected' => false,
        ]);

        return response()->json([
            'message' => 'Telegram disconnected successfully',
        ]);
    }

    /**
     * Telegram webhook handler
     *
     * POST /api/webhook/telegram
     */
    public function webhook(Request $request): JsonResponse
    {
        Log::info('Telegram webhook received', ['update' => $request->all()]);

        // TODO: Full webhook handling (commands, callback queries)
        return response()->json(['ok' => true]);
    }
}
