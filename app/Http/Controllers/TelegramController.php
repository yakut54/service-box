<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Order;
use App\Models\Shop;
use App\Services\TelegramService;
use App\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
        $update = $request->all();
        Log::info('Telegram webhook received', ['update' => $update]);

        try {
            // ── Handle /start command ──────────────────────────────────
            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            }

            // ── Handle inline keyboard callback ───────────────────────
            if (isset($update['callback_query'])) {
                $this->handleCallbackQuery($update['callback_query']);
            }
        } catch (\Throwable $e) {
            Log::error('Telegram webhook error', ['error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    // ── Private handlers ──────────────────────────────────────────────

    private function handleMessage(array $message): void
    {
        $text   = $message['text'] ?? '';
        $chatId = $message['chat']['id'] ?? null;

        if (!$chatId) {
            return;
        }

        // Extract code: "/start CODE", plain "CODE" (6 alphanumeric), or bare "/start"
        $code = null;
        $isStart = str_starts_with($text, '/start');

        if ($isStart) {
            $parts = explode(' ', $text, 2);
            $code  = isset($parts[1]) ? trim($parts[1]) : null;
        } elseif (preg_match('/^[A-Z0-9]{6}$/i', trim($text))) {
            $code = trim($text);
        }

        if ($isStart && !$code) {
            $this->sendReply(
                $chatId,
                "👋 Привет! Я бот ServiceBox.\n\n" .
                "Чтобы подключить уведомления, зайдите в <b>Настройки → Telegram</b> в вашей админке и отправьте сюда сгенерированный код."
            );
            return;
        }

        if ($code) {
            // Customer link token: starts with 'c', 33 chars total (c + 32 hex)
            if (str_starts_with($code, 'c') && strlen($code) === 33) {
                $this->handleCustomerLink(ltrim($code, 'c'), $chatId);
                return;
            }

            // Shop connection code: 6 alphanumeric chars
            $shop = TelegramService::verifyConnectionCode(strtoupper($code));

            if (!$shop) {
                $this->sendReply($chatId, '❌ Код неверный или истёк. Сгенерируйте новый в <b>Настройки → Telegram</b>.');
                return;
            }

            TelegramService::connectTelegram($shop, $chatId);

            $this->sendReply(
                $chatId,
                "✅ <b>Готово!</b> Бот подключён к <b>{$shop->name}</b>\n\nБудете получать уведомления о новых записях и заказах прямо сюда."
            );
        }
    }

    private function handleCustomerLink(string $token, int $chatId): void
    {
        // Find the token across all shop schemas
        $schemas = \DB::select("
            SELECT schema_name FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            $s = $row->schema_name;

            $record = \DB::table("{$s}.telegram_link_tokens")
                ->where('token', $token)
                ->where('used', false)
                ->where('expires_at', '>', now())
                ->first();

            if (!$record) {
                continue;
            }

            // Mark used
            \DB::table("{$s}.telegram_link_tokens")
                ->where('token', $token)
                ->update(['used' => true]);

            // Link customer
            \DB::table("{$s}.customers")
                ->where('phone', $record->customer_phone)
                ->update(['telegram_chat_id' => $chatId]);

            $this->sendReply(
                $chatId,
                "✅ <b>Готово!</b> Теперь вы будете получать уведомления о записях прямо сюда."
            );
            return;
        }

        $this->sendReply($chatId, '❌ Ссылка недействительна или устарела. Создайте новую запись.');
    }

    private function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackId = $callbackQuery['id'];
        $chatId     = $callbackQuery['message']['chat']['id'] ?? null;
        $data       = $callbackQuery['data'] ?? null;

        if (!$chatId || !$data) {
            return;
        }

        // Format: "entity:action:uuid"  e.g. "order:confirm:abc123"
        $parts = explode(':', $data, 3);
        if (count($parts) !== 3) {
            $this->answerCallback($callbackId, 'Неизвестная команда');
            return;
        }

        [$entityType, $action, $entityId] = $parts;

        // Find shop by chat_id
        $shop = Shop::where('telegram_chat_id', $chatId)
            ->where('telegram_bot_connected', true)
            ->first();

        if (!$shop) {
            $this->answerCallback($callbackId, 'Магазин не найден');
            return;
        }

        // Set tenant context for this shop
        TenantService::setContext($shop);

        try {
            match ($entityType) {
                'order'   => $this->handleOrderAction($callbackId, $entityId, $action, $chatId),
                'booking' => $this->handleBookingAction($callbackId, $entityId, $action, $chatId, $shop),
                default   => $this->answerCallback($callbackId, 'Неизвестный тип'),
            };
        } finally {
            TenantService::resetContext();
        }
    }

    private function handleOrderAction(string $callbackId, string $orderId, string $action, int $chatId): void
    {
        $order = Order::find($orderId);

        if (!$order) {
            $this->answerCallback($callbackId, 'Заказ не найден');
            return;
        }

        $newStatus = match ($action) {
            'confirm' => 'processing',
            'cancel'  => 'cancelled',
            default   => null,
        };

        if (!$newStatus) {
            $this->answerCallback($callbackId, 'Неизвестное действие');
            return;
        }

        $order->update(['status' => $newStatus]);

        $label = $newStatus === 'processing' ? '✅ Подтверждён' : '❌ Отменён';
        $this->answerCallback($callbackId, "Заказ {$label}");

        $shortId = substr($orderId, 0, 8);
        $this->sendReply($chatId, "Заказ #{$shortId} — {$label}");

        Log::info('Order status updated via Telegram', [
            'order_id' => $orderId,
            'status'   => $newStatus,
        ]);
    }

    private function handleBookingAction(string $callbackId, string $bookingId, string $action, int $chatId, Shop $shop): void
    {
        $booking = Booking::find($bookingId);

        if (!$booking) {
            $this->answerCallback($callbackId, 'Запись не найдена');
            return;
        }

        $newStatus = match ($action) {
            'confirm' => 'confirmed',
            'cancel'  => 'cancelled',
            default   => null,
        };

        if (!$newStatus) {
            $this->answerCallback($callbackId, 'Неизвестное действие');
            return;
        }

        $booking->load(['service', 'master']);
        $booking->update(['status' => $newStatus]);

        $label = $newStatus === 'confirmed' ? '✅ Подтверждена' : '❌ Отменена';
        $this->answerCallback($callbackId, "Запись {$label}");

        $date = \Carbon\Carbon::parse($booking->start_time)->format('d.m H:i');
        $this->sendReply($chatId, "Запись {$date} ({$booking->customer_name}) — {$label}");

        try {
            TelegramService::notifyBookingStatusToCustomer($shop, $booking, $newStatus);
        } catch (\Throwable) {}

        Log::info('Booking status updated via Telegram', [
            'booking_id' => $bookingId,
            'status'     => $newStatus,
        ]);
    }

    // ── Telegram Bot API helpers ──────────────────────────────────────

    private function sendReply(int $chatId, string $text): void
    {
        $botToken = config('services.telegram.bot_token');
        if (!$botToken) {
            return;
        }

        Http::timeout(5)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    private function answerCallback(string $callbackId, string $text): void
    {
        $botToken = config('services.telegram.bot_token');
        if (!$botToken) {
            return;
        }

        Http::timeout(5)->post("https://api.telegram.org/bot{$botToken}/answerCallbackQuery", [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => false,
        ]);
    }
}
