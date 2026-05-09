<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Order;
use App\Models\Shop;
use App\Services\MaxService;
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

        if (!$shop->hasFeature('telegram')) {
            return response()->json([
                'error'   => 'plan_gate',
                'message' => 'Telegram-уведомления доступны на тарифах Start и выше.',
            ], 403);
        }

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
        $messageId  = $callbackQuery['message']['message_id'] ?? null;
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

        // Customer-side rating callback — shop lookup is by booking id, not chat_id
        if ($entityType === 'rate') {
            $this->handleRatingCallback($callbackId, $action, $entityId, $chatId);
            return;
        }

        if ($entityType === 'client') {
            $this->handleClientAction($callbackId, $action, $entityId, $chatId, $messageId);
            return;
        }

        // Find shop by chat_id (owner-side callbacks)
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
                'order'   => $this->handleOrderAction($callbackId, $entityId, $action, $chatId, $messageId),
                'booking' => $this->handleBookingAction($callbackId, $entityId, $action, $chatId, $messageId, $shop),
                default   => $this->answerCallback($callbackId, 'Неизвестный тип'),
            };
        } finally {
            TenantService::resetContext();
        }
    }

    private function handleOrderAction(string $callbackId, string $orderId, string $action, int $chatId, ?int $messageId): void
    {
        $order = Order::find($orderId);

        if (!$order) {
            $this->answerCallback($callbackId, 'Заказ не найден');
            return;
        }

        if (in_array($order->status, ['completed', 'processing', 'cancelled'])) {
            $this->answerCallback($callbackId, 'Статус уже изменён', true);
            return;
        }

        $isDigitalOnly = $order->items()->where('product_type', '!=', 'digital')->doesntExist();

        $newStatus = match ($action) {
            'confirm' => $isDigitalOnly ? 'completed' : 'processing',
            'cancel'  => 'cancelled',
            default   => null,
        };

        if (!$newStatus) {
            $this->answerCallback($callbackId, 'Неизвестное действие');
            return;
        }

        $order->update(['status' => $newStatus]);

        $label = match ($newStatus) {
            'completed'  => '✅ Подтверждён',
            'processing' => '✅ Подтверждён',
            default      => '❌ Отменён',
        };

        $this->answerCallback($callbackId, "Заказ {$label}");
        $this->removeKeyboard($chatId, $messageId);
        MaxService::removeButtonsByEntity('order', $orderId);

        $shortId = substr($orderId, 0, 8);
        $this->sendReply($chatId, "Заказ #{$shortId} — {$label}");

        $shop = Shop::where('telegram_chat_id', $chatId)->where('telegram_bot_connected', true)->first();
        if ($shop) MaxService::sendMessage($shop, "Заказ #{$shortId} — {$label}");

        Log::info('Order status updated via Telegram', [
            'order_id' => $orderId,
            'status'   => $newStatus,
        ]);
    }

    private function handleBookingAction(string $callbackId, string $bookingId, string $action, int $chatId, ?int $messageId, Shop $shop): void
    {
        $booking = Booking::find($bookingId);

        if (!$booking) {
            $this->answerCallback($callbackId, 'Запись не найдена');
            return;
        }

        if (in_array($booking->status, ['confirmed', 'cancelled', 'completed', 'no_show'])) {
            $this->answerCallback($callbackId, 'Статус уже изменён', true);
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

        try { \App\Services\MaxService::notifyCustomerStatus($bookingId, $newStatus, $booking, $shop->timezone ?? 'Europe/Moscow'); } catch (\Throwable) {}

        $label = $newStatus === 'confirmed' ? '✅ Подтверждена' : '❌ Отменена';

        $this->answerCallback($callbackId, "Запись {$label}");
        $this->removeKeyboard($chatId, $messageId);
        MaxService::removeButtonsByEntity('booking', $bookingId);

        $date = \Carbon\Carbon::parse($booking->start_time)->setTimezone($shop->timezone ?? 'Europe/Moscow')->format('d.m H:i');
        $this->sendReply($chatId, "Запись {$date} ({$booking->customer_name}) — {$label}");
        MaxService::sendMessage($shop, "Запись {$date} ({$booking->customer_name}) — {$label}");

        try {
            TelegramService::notifyBookingStatusToCustomer($shop, $booking, $newStatus);
        } catch (\Throwable) {}

        Log::info('Booking status updated via Telegram', [
            'booking_id' => $bookingId,
            'status'     => $newStatus,
        ]);
    }

    private function handleRatingCallback(string $callbackId, string $bookingId, string $scoreStr, int $chatId): void
    {
        $score = (int) $scoreStr;
        if ($score < 1 || $score > 5) {
            $this->answerCallback($callbackId, 'Неверная оценка');
            return;
        }

        // Find the booking across all shop schemas
        $schemas = \DB::select("
            SELECT schema_name FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            $s = $row->schema_name;

            $booking = \DB::selectOne("
                SELECT b.id, b.service_id, b.customer_id, b.customer_name, b.customer_phone, b.rating_sent
                FROM {$s}.bookings b
                WHERE b.id = ?
            ", [$bookingId]);

            if (!$booking) {
                continue;
            }

            if ($booking->rating_sent) {
                $this->answerCallback($callbackId, 'Вы уже оценили этот визит');
                return;
            }

            // Verify the rating comes from the right customer
            $customer = \DB::selectOne(
                "SELECT telegram_chat_id FROM {$s}.customers WHERE phone = ?",
                [$booking->customer_phone]
            );

            if (!$customer || (int) $customer->telegram_chat_id !== $chatId) {
                $this->answerCallback($callbackId, 'Нет доступа');
                return;
            }

            try {
                \DB::table("{$s}.reviews")->insert([
                    'id'            => (string) \Illuminate\Support\Str::uuid(),
                    'product_id'    => $booking->service_id,
                    'customer_id'   => $booking->customer_id,
                    'customer_name' => $booking->customer_name,
                    'rating'        => $score,
                    'is_published'  => false,
                    'created_at'    => now(),
                ]);

                \DB::statement("UPDATE {$s}.bookings SET rating_sent = TRUE WHERE id = ?", [$bookingId]);
            } catch (\Throwable $e) {
                Log::error('Failed to save rating', ['booking' => $bookingId, 'error' => $e->getMessage()]);
                $this->answerCallback($callbackId, 'Ошибка сохранения');
                return;
            }

            $stars = str_repeat('⭐', $score);
            $this->answerCallback($callbackId, "Спасибо за оценку! {$stars}");
            $this->sendReply($chatId, "Спасибо за вашу оценку {$stars}\nОтзыв отправлен на модерацию.");
            return;
        }

        $this->answerCallback($callbackId, 'Запись не найдена');
    }

    private function handleClientAction(string $callbackId, string $action, string $bookingId, int $chatId, ?int $messageId = null): void
    {
        if ($action !== 'cancel') {
            $this->answerCallback($callbackId, 'Неизвестное действие');
            return;
        }

        $schemas = \DB::select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'shop_%'");

        foreach ($schemas as $row) {
            $s = $row->schema_name;

            $booking = \DB::selectOne("
                SELECT b.id, b.status, b.customer_name, b.customer_phone, b.start_time
                FROM {$s}.bookings b WHERE b.id = ?
            ", [$bookingId]);

            if (!$booking) continue;

            // Verify the request comes from the booking's customer
            $customer = \DB::selectOne(
                "SELECT telegram_chat_id FROM {$s}.customers WHERE phone = ?",
                [$booking->customer_phone]
            );
            if (!$customer || (int) $customer->telegram_chat_id !== $chatId) {
                $this->answerCallback($callbackId, 'Нет доступа');
                return;
            }

            if (!in_array($booking->status, ['pending', 'confirmed'])) {
                $this->answerCallback($callbackId, 'Запись уже нельзя отменить', true);
                return;
            }

            \DB::statement("UPDATE {$s}.bookings SET status = 'cancelled' WHERE id = ?", [$bookingId]);

            $this->answerCallback($callbackId, 'Запись отменена');
            $this->removeKeyboard($chatId, $messageId);
            $this->sendReply($chatId, "❌ Ваша запись отменена.");

            $shop = Shop::where('schema_name', $row->schema_name)->first();
            if ($shop) {
                $date = \Carbon\Carbon::parse($booking->start_time)
                    ->setTimezone($shop->timezone ?? 'Europe/Moscow')
                    ->format('d.m H:i');
                $msg = "❌ Клиент отменил запись {$date} ({$booking->customer_name})";
                TelegramService::sendMessage(shop: $shop, text: $msg);
                MaxService::sendMessage($shop, $msg);
            }

            Log::info('Booking cancelled by customer via Telegram', ['booking_id' => $bookingId]);
            return;
        }

        $this->answerCallback($callbackId, 'Запись не найдена');
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

    private function answerCallback(string $callbackId, string $text, bool $showAlert = false): void
    {
        $botToken = config('services.telegram.bot_token');
        if (!$botToken) {
            return;
        }

        Http::timeout(5)->post("https://api.telegram.org/bot{$botToken}/answerCallbackQuery", [
            'callback_query_id' => $callbackId,
            'text'              => $text,
            'show_alert'        => $showAlert,
        ]);
    }

    private function removeKeyboard(int $chatId, ?int $messageId): void
    {
        if (!$messageId) {
            return;
        }

        $botToken = config('services.telegram.bot_token');
        if (!$botToken) {
            return;
        }

        Http::timeout(5)->post("https://api.telegram.org/bot{$botToken}/editMessageReplyMarkup", [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => ['inline_keyboard' => []],
        ]);
    }
}
