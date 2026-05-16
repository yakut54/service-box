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
use Illuminate\Support\Facades\Log;

class MaxController extends Controller
{
    // ── Admin API ─────────────────────────────────────────────────────────

    public function generateCode(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        if (!in_array($shop->subscription_plan, ['start', 'business', 'pro'])) {
            return response()->json([
                'error'   => 'plan_gate',
                'message' => 'MAX-уведомления доступны на тарифах Start и выше.',
            ], 403);
        }

        $code = MaxService::generateConnectionCode($shop);

        return response()->json([
            'code'               => $code,
            'expires_in_minutes' => 10,
            'bot_username'       => config('services.max.bot_username'),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        return response()->json([
            'connected' => (bool) $shop->max_bot_connected,
            'user_id'   => $shop->max_chat_id,
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $shop->update([
            'max_chat_id'       => null,
            'max_bot_connected' => false,
        ]);

        return response()->json(['message' => 'MAX disconnected']);
    }

    // ── Webhook ───────────────────────────────────────────────────────────

    public function webhook(Request $request): JsonResponse
    {
        $update = $request->all();
        Log::info('MAX webhook', ['type' => $update['update_type'] ?? 'unknown']);

        try {
            match ($update['update_type'] ?? '') {
                'bot_started'      => $this->handleBotStarted($update),
                'message_created'  => $this->handleMessage($update),
                'message_callback' => $this->handleCallback($update),
                default            => null,
            };
        } catch (\Throwable $e) {
            Log::error('MAX webhook error', ['error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    // ── Handlers ──────────────────────────────────────────────────────────

    private function handleBotStarted(array $update): void
    {
        $userId = $update['user']['user_id'] ?? null;
        if (!$userId) return;

        $code = isset($update['payload']) ? trim($update['payload']) : null;

        if ($code) {
            $code = strtoupper($code);
            $bookingId = MaxService::verifyCustomerCode($code);
            if ($bookingId) {
                $this->trySubscribeCustomer($userId, $bookingId);
                return;
            }
            $this->tryConnect($userId, $code);
            return;
        }

        MaxService::sendRaw(
            $userId,
            "👋 Привет! Я бот ServiceBox.\n\n" .
            "Чтобы подключить уведомления, зайдите в <b>Настройки → MAX</b> в вашей админке и нажмите «Подключить через MAX»."
        );
    }

    private function handleMessage(array $update): void
    {
        $text   = $update['message']['body']['text'] ?? '';
        $userId = $update['message']['sender']['user_id'] ?? null;

        if (!$userId || !$text) return;

        $text = trim($text);

        // /start or bare code
        if (str_starts_with($text, '/start')) {
            $parts = explode(' ', $text, 2);
            $code  = isset($parts[1]) ? trim($parts[1]) : null;

            if (!$code) {
                MaxService::sendRaw(
                    $userId,
                    "👋 Привет! Отправьте код из <b>Настройки → MAX</b> в вашей админке."
                );
                return;
            }

            $this->tryConnect($userId, strtoupper($code));
            return;
        }

        if (preg_match('/^[A-Z0-9]{6}$/i', $text)) {
            $code = strtoupper($text);

            // Try customer booking code first
            $bookingId = MaxService::verifyCustomerCode($code);
            if ($bookingId) {
                $this->trySubscribeCustomer($userId, $bookingId);
                return;
            }

            // Fall back to shop owner connect code
            $this->tryConnect($userId, $code);
            return;
        }

        $pendingBookingId = \Illuminate\Support\Facades\Cache::get("awaiting_review:max:{$userId}");
        if ($pendingBookingId) {
            \Illuminate\Support\Facades\Cache::forget("awaiting_review:max:{$userId}");
            $cached = \Illuminate\Support\Facades\Cache::get("review_id:{$pendingBookingId}");
            if ($cached) {
                \DB::table("{$cached['schema']}.reviews")
                    ->where('id', $cached['id'])
                    ->update(['text' => $text]);
                Log::info('[MAX] review: text saved', ['booking' => $pendingBookingId]);
            }
            MaxService::sendRaw($userId, '✅ Спасибо! Ваш отзыв сохранён.');
            return;
        }

        MaxService::sendRaw($userId, "ℹ️ Этот бот отправляет уведомления о ваших записях.\n\nЧтобы подтвердить или отменить запись —\nиспользуйте кнопки в сообщениях бота.");
    }

    private function trySubscribeCustomer(int $userId, string $bookingId): void
    {
        MaxService::subscribeCustomer($bookingId, $userId);

        MaxService::sendRaw(
            $userId,
            "✅ Готово! Вы подписаны на уведомления по вашей записи.\n\nКак только статус изменится — я сразу напишу."
        );
    }

    private function tryConnect(int $userId, string $code): void
    {
        $shop = MaxService::verifyConnectionCode($code);

        if (!$shop) {
            MaxService::sendRaw($userId, '❌ Код неверный или истёк. Сгенерируйте новый в <b>Настройки → MAX</b>.');
            return;
        }

        MaxService::connectMax($shop, $userId);

        MaxService::sendRaw(
            $userId,
            "✅ <b>Готово!</b> Бот подключён к <b>{$shop->name}</b>\n\nБудете получать уведомления о новых записях и заказах прямо сюда."
        );
    }

    private function handleCallback(array $update): void
    {
        $callbackId = $update['callback']['callback_id'] ?? null;
        $payload    = $update['callback']['payload'] ?? '';
        $userId     = $update['callback']['user']['user_id'] ?? null;

        Log::info('[MAX] callback received', ['payload' => $payload, 'user_id' => $userId]);

        if (!$callbackId || !$userId) return;

        $parts = explode(':', $payload, 3);
        if (count($parts) !== 3) {
            Log::warning('[MAX] callback: bad payload format', ['payload' => $payload]);
            MaxService::answerCallback($callbackId, 'Неизвестная команда');
            return;
        }

        [$entityType, $action, $entityId] = $parts;

        Log::info('[MAX] callback parsed', ['entity' => $entityType, 'action' => $action, 'id' => $entityId]);

        // Customer-side callbacks — handle before shop owner lookup
        if ($entityType === 'rate') {
            $this->handleRatingCallback($callbackId, $action, $entityId, $userId);
            return;
        }

        if ($entityType === 'client') {
            $this->handleClientAction($callbackId, $action, $entityId, $userId);
            return;
        }

        if ($entityType === 'review') {
            $this->handleReviewCallback($callbackId, $action, $entityId, $userId);
            return;
        }

        $shop = Shop::where('max_chat_id', $userId)
            ->where('max_bot_connected', true)
            ->first();

        if (!$shop) {
            MaxService::answerCallback($callbackId, 'Магазин не найден');
            return;
        }

        TenantService::setContext($shop);

        try {
            match ($entityType) {
                'order'   => $this->handleOrderAction($callbackId, $entityId, $action, $userId, $shop),
                'booking' => $this->handleBookingAction($callbackId, $entityId, $action, $userId, $shop),
                default   => MaxService::answerCallback($callbackId, 'Неизвестный тип'),
            };
        } finally {
            TenantService::resetContext();
        }
    }

    private function handleOrderAction(string $cbId, string $orderId, string $action, int $userId, Shop $shop): void
    {
        $order = Order::find($orderId);

        if (!$order) {
            MaxService::answerCallback($cbId, 'Заказ не найден');
            return;
        }

        $isDigitalOnly = $order->items()->where('product_type', '!=', 'digital')->doesntExist();

        $newStatus = match ($action) {
            'confirm' => $isDigitalOnly ? 'completed' : 'processing',
            'cancel'  => 'cancelled',
            default   => null,
        };

        if (!$newStatus) {
            MaxService::answerCallback($cbId, 'Неизвестное действие');
            return;
        }

        $order->update(['status' => $newStatus]);

        $label = match ($newStatus) {
            'completed', 'processing' => '✅ Подтверждён',
            default                   => '❌ Отменён',
        };

        MaxService::answerCallback($cbId, "Заказ {$label}");
        MaxService::removeButtonsByEntity('order', $orderId);
        TelegramService::removeKeyboardByEntity('Order', $orderId);
        MaxService::sendRaw($userId, "Заказ #" . substr($orderId, 0, 8) . " — {$label}");
        TelegramService::sendMessage(shop: $shop, text: "Заказ #" . substr($orderId, 0, 8) . " — {$label}");

        Log::info('Order updated via MAX', ['order_id' => $orderId, 'status' => $newStatus]);
    }

    private function handleBookingAction(string $cbId, string $bookingId, string $action, int $userId, Shop $shop): void
    {
        $booking = Booking::find($bookingId);

        if (!$booking) {
            MaxService::answerCallback($cbId, 'Запись не найдена');
            return;
        }

        $newStatus = match ($action) {
            'confirm' => 'confirmed',
            'cancel'  => 'cancelled',
            default   => null,
        };

        if (!$newStatus) {
            MaxService::answerCallback($cbId, 'Неизвестное действие');
            return;
        }

        $booking->load(['service', 'master']);
        $booking->update(['status' => $newStatus]);

        MaxService::notifyCustomerStatus($bookingId, $newStatus, $booking, $shop->timezone ?? 'Europe/Moscow');
        try { TelegramService::notifyBookingStatusToCustomer($shop, $booking, $newStatus); } catch (\Throwable) {}

        $label = $newStatus === 'confirmed' ? '✅ Подтверждена' : '❌ Отменена';
        MaxService::answerCallback($cbId, "Запись {$label}");
        MaxService::removeButtonsByEntity('booking', $bookingId);
        TelegramService::removeKeyboardByEntity('Booking', $bookingId);

        $date = \Carbon\Carbon::parse($booking->start_time)
            ->setTimezone($shop->timezone ?? 'Europe/Moscow')
            ->format('d.m H:i');

        MaxService::sendRaw($userId, "Запись {$date} ({$booking->customer_name}) — {$label}");
        TelegramService::sendMessage(shop: $shop, text: "Запись {$date} ({$booking->customer_name}) — {$label}");

        Log::info('Booking updated via MAX', ['booking_id' => $bookingId, 'status' => $newStatus]);
    }

    private function handleReviewCallback(string $cbId, string $action, string $bookingId, int $userId): void
    {
        if ($action !== 'write') {
            MaxService::answerCallback($cbId, 'Неизвестное действие');
            return;
        }

        \Illuminate\Support\Facades\Cache::put("awaiting_review:max:{$userId}", $bookingId, now()->addMinutes(15));
        MaxService::answerCallback($cbId, '');
        MaxService::sendRaw($userId, 'Напишите ваш отзыв:');
        Log::info('[MAX] review: awaiting text', ['booking' => $bookingId, 'user' => $userId]);
    }

    private function handleClientAction(string $cbId, string $action, string $bookingId, int $userId): void
    {
        if ($action !== 'cancel') {
            MaxService::answerCallback($cbId, 'Неизвестное действие');
            return;
        }

        if (MaxService::getCustomerUserId($bookingId) !== $userId) {
            MaxService::answerCallback($cbId, 'Нет доступа');
            return;
        }

        $schemas = \DB::select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'shop_%'");

        foreach ($schemas as $row) {
            $s = $row->schema_name;

            $booking = \DB::selectOne("
                SELECT b.id, b.status, b.customer_name, b.start_time
                FROM {$s}.bookings b WHERE b.id = ?
            ", [$bookingId]);

            if (!$booking) continue;

            if (!in_array($booking->status, ['pending', 'confirmed'])) {
                MaxService::answerCallback($cbId, 'Запись уже нельзя отменить');
                return;
            }

            \DB::statement("UPDATE {$s}.bookings SET status = 'cancelled' WHERE id = ?", [$bookingId]);

            MaxService::answerCallback($cbId, 'Запись отменена');

            foreach (['max_cust_cancel_mid', 'max_reminder_cancel_mid'] as $cacheKey) {
                $cached = \Illuminate\Support\Facades\Cache::get("{$cacheKey}:{$bookingId}");
                if ($cached) {
                    MaxService::removeButtons((int) $cached['user_id'], $cached['mid']);
                }
            }

            MaxService::sendRaw($userId, "❌ Ваша запись отменена.");

            $shop = Shop::where('schema_name', $row->schema_name)->first();
            if ($shop) {
                $date = \Carbon\Carbon::parse($booking->start_time)
                    ->setTimezone($shop->timezone ?? 'Europe/Moscow')
                    ->format('d.m H:i');
                $msg = "❌ Клиент отменил запись {$date} ({$booking->customer_name})";
                MaxService::sendMessage($shop, $msg);
                TelegramService::sendMessage(shop: $shop, text: $msg);
            }

            Log::info('Booking cancelled by customer via MAX', ['booking_id' => $bookingId]);
            return;
        }

        MaxService::answerCallback($cbId, 'Запись не найдена');
    }

    private function handleRatingCallback(string $cbId, string $bookingId, string $scoreStr, int $userId): void
    {
        $score = (int) $scoreStr;
        Log::info('[MAX] rating callback', ['booking' => $bookingId, 'score' => $score, 'user_id' => $userId]);

        if ($score < 1 || $score > 5) {
            Log::warning('[MAX] rating: invalid score', ['score' => $score]);
            MaxService::answerCallback($cbId, 'Неверная оценка');
            return;
        }

        if (\Illuminate\Support\Facades\Cache::get("rated:{$bookingId}")) {
            Log::info('[MAX] rating: already rated (cache hit)', ['booking' => $bookingId]);
            MaxService::answerCallback($cbId, 'Вы уже оценили этот визит');
            return;
        }

        $schemas = \DB::select("
            SELECT schema_name FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            $s = $row->schema_name;

            $booking = \DB::selectOne("
                SELECT id, service_id, customer_id, customer_name
                FROM {$s}.bookings WHERE id = ?
            ", [$bookingId]);

            if (!$booking) continue;

            Log::info('[MAX] rating: booking found', ['schema' => $s, 'booking' => $bookingId]);

            $subscribedUserId = MaxService::getCustomerUserId($bookingId);
            Log::info('[MAX] rating: customer lookup', [
                'subscribed_user_id' => $subscribedUserId,
                'callback_user_id'   => $userId,
                'match'              => $subscribedUserId === $userId,
            ]);

            if ($subscribedUserId !== $userId) {
                Log::warning('[MAX] rating: access denied');
                MaxService::answerCallback($cbId, 'Нет доступа');
                return;
            }

            $reviewId = (string) \Illuminate\Support\Str::uuid();

            try {
                \DB::table("{$s}.reviews")->insert([
                    'id'            => $reviewId,
                    'product_id'    => $booking->service_id,
                    'customer_id'   => $booking->customer_id,
                    'customer_name' => $booking->customer_name,
                    'rating'        => $score,
                    'is_published'  => false,
                    'created_at'    => now(),
                ]);
            } catch (\Throwable $e) {
                Log::error('[MAX] rating: DB insert FAILED', ['booking' => $bookingId, 'error' => $e->getMessage()]);
                MaxService::answerCallback($cbId, 'Ошибка сохранения');
                return;
            }

            \Illuminate\Support\Facades\Cache::put("rated:{$bookingId}", true, now()->addDays(30));
            \Illuminate\Support\Facades\Cache::put("review_id:{$bookingId}", ['schema' => $s, 'id' => $reviewId], now()->addDays(30));

            $cached = \Illuminate\Support\Facades\Cache::get("max_rating_mid:{$bookingId}");
            Log::info('[MAX] rating: mid cache', ['found' => (bool) $cached, 'mid' => $cached['mid'] ?? null]);
            if ($cached) {
                MaxService::removeButtons((int) $cached['user_id'], $cached['mid']);
            }

            $stars = str_repeat('⭐', $score);
            MaxService::answerCallback($cbId, "Спасибо за оценку! {$stars}");
            MaxService::sendRaw($userId, "Спасибо за оценку {$stars}", [[
                ['type' => 'callback', 'text' => '✍️ Написать отзыв', 'payload' => "review:write:{$bookingId}"],
            ]]);
            Log::info('[MAX] rating: saved OK', ['booking' => $bookingId, 'score' => $score]);
            return;
        }

        Log::warning('[MAX] rating: booking not found in any schema', ['booking' => $bookingId]);
        MaxService::answerCallback($cbId, 'Запись не найдена');
    }
}
