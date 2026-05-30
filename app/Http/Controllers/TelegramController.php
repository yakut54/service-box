<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Order;
use App\Models\Shop;
use App\Services\MasterBotService;
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

        if (!in_array($shop->subscription_plan, ['start', 'business', 'pro'])) {
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

            // Master link token: starts with 'm', 33 chars total (m + 32 hex)
            if (str_starts_with($code, 'm') && strlen($code) === 33) {
                $this->handleMasterLink(substr($code, 1), $chatId);
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
            return;
        }

        // ── Master commands (keyboard buttons + slash commands) ───────────
        $masterCmd = match(true) {
            in_array($text, ['📅 Сегодня', '/today'])   => 'today',
            in_array($text, ['📅 Неделя',  '/week'])    => 'week',
            in_array($text, ['📊 Статистика', '/stats']) => 'stats',
            default => null,
        };

        if ($masterCmd !== null) {
            $ctx = MasterBotService::findByTelegramChatId($chatId);
            if ($ctx) {
                $responseText = match($masterCmd) {
                    'today' => MasterBotService::getScheduleText($ctx['schema'], $ctx['master_id'], $ctx['tz'], 'today', $ctx['hide_phone']),
                    'week'  => MasterBotService::getScheduleText($ctx['schema'], $ctx['master_id'], $ctx['tz'], 'week',  $ctx['hide_phone']),
                    'stats' => MasterBotService::getStatsText($ctx['schema'], $ctx['master_id'], $ctx['tz']),
                };
                TelegramService::sendToMaster($chatId, $responseText);
                return;
            }
        }

        $pendingBookingId = \Illuminate\Support\Facades\Cache::get("awaiting_review:tg:{$chatId}");
        if ($pendingBookingId) {
            \Illuminate\Support\Facades\Cache::forget("awaiting_review:tg:{$chatId}");
            $cached = \Illuminate\Support\Facades\Cache::get("review_id:{$pendingBookingId}");
            if ($cached) {
                \DB::statement(
                    "UPDATE {$cached['schema']}.reviews SET text = ? WHERE id = ?",
                    [$text, $cached['id']]
                );
                Log::info('[TG] review: text saved', ['booking' => $pendingBookingId]);
                try {
                    MasterBotService::notifyAllOnReview(
                        $cached['schema'],
                        $cached['customer_name'] ?? '—',
                        $cached['service_name']  ?? '—',
                        $cached['master_id']     ?? null,
                        $cached['score']         ?? 5,
                        $text
                    );
                } catch (\Throwable) {}
            }
            $reviewBtn = \Illuminate\Support\Facades\Cache::get("tg_review_btn_mid:{$pendingBookingId}");
            if ($reviewBtn) $this->removeKeyboard($reviewBtn['chat_id'], $reviewBtn['message_id']);
            $this->sendReply($chatId, '✅ Спасибо! Ваш отзыв отправлен на модерацию.');
            return;
        }

        $this->sendReply($chatId, "ℹ️ Этот бот отправляет уведомления о ваших записях.\n\nЧтобы подтвердить или отменить запись —\nиспользуйте кнопки в сообщениях бота.");
    }

    private function handleCustomerLink(string $token, int $chatId): void
    {
        // Find the token across all shop schemas
        $schemas = \DB::select("
            SELECT schema_name FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            $s = $this->safeSchema($row->schema_name);

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

    private function handleMasterLink(string $token, int $chatId): void
    {
        $staff = \App\Models\ShopStaff::where('messenger_link_token', $token)
            ->where('role', 'master')
            ->where('messenger_link_token_expires_at', '>', now())
            ->first();

        if (!$staff) {
            $this->sendReply($chatId, '❌ Ссылка недействительна или устарела. Создайте новую в портале мастера.');
            return;
        }

        $staff->update([
            'telegram_chat_id'                => $chatId,
            'messenger_link_token'            => null,
            'messenger_link_token_expires_at' => null,
        ]);

        TelegramService::sendMasterWelcome($chatId);
        Log::info('[TG] master linked', ['staff_id' => $staff->id, 'chat_id' => $chatId]);
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

        Log::info('[TG] callback', ['entity' => $entityType, 'action' => $action, 'id' => $entityId, 'chat' => $chatId]);

        // Customer-side rating callback — shop lookup is by booking id, not chat_id
        if ($entityType === 'rate') {
            $this->handleRatingCallback($callbackId, $action, $entityId, $chatId, $messageId);
            return;
        }

        if ($entityType === 'client') {
            $this->handleClientAction($callbackId, $action, $entityId, $chatId, $messageId);
            return;
        }

        if ($entityType === 'review') {
            $this->handleReviewCallback($callbackId, $action, $entityId, $chatId, $messageId);
            return;
        }

        if ($entityType === 'master_booking') {
            $this->handleMasterBookingAction($callbackId, $action, $entityId, $chatId, $messageId);
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

        $validFrom = match($action) {
            'confirm', 'cancel'  => ['pending'],
            'complete', 'noshow' => ['confirmed'],
            default              => [],
        };

        if (!in_array($booking->status, $validFrom)) {
            $this->answerCallback($callbackId, 'Статус уже изменён', true);
            return;
        }

        $newStatus = match ($action) {
            'confirm'  => 'confirmed',
            'cancel'   => 'cancelled',
            'complete' => 'completed',
            'noshow'   => 'no_show',
            default    => null,
        };

        if (!$newStatus) {
            $this->answerCallback($callbackId, 'Неизвестное действие');
            return;
        }

        $booking->load(['service', 'master']);
        $booking->update(['status' => $newStatus]);

        try { \App\Services\MaxService::notifyCustomerStatus($bookingId, $newStatus, $booking, $shop->timezone ?? 'Europe/Moscow'); } catch (\Throwable) {}
        try { TelegramService::notifyBookingStatusToCustomer($shop, $booking, $newStatus); } catch (\Throwable) {}

        if ($newStatus === 'completed' && !$booking->rating_sent) {
            try { TelegramService::notifyRatingRequest($shop, $booking); } catch (\Throwable) {}
            try { \App\Services\MaxService::notifyRatingRequest($bookingId, $booking); } catch (\Throwable) {}
            $booking->update(['rating_sent' => true]);
        }

        $label = match($newStatus) {
            'confirmed' => '✅ Подтверждена',
            'cancelled' => '❌ Отменена',
            'completed' => '✅ Завершена',
            'no_show'   => '👻 Неявка',
            default     => $newStatus,
        };

        $this->answerCallback($callbackId, "Запись {$label}");
        $this->removeKeyboard($chatId, $messageId);
        MaxService::removeButtonsByEntity('booking', $bookingId);
        $cached = \Illuminate\Support\Facades\Cache::get("max_mid:completion:{$bookingId}");
        if ($cached) MaxService::removeButtons((int) $cached['user_id'], $cached['mid']);

        $date = \Carbon\Carbon::parse($booking->start_time)->setTimezone($shop->timezone ?? 'Europe/Moscow')->format('d.m H:i');
        $this->sendReply($chatId, "Запись {$date} ({$booking->customer_name}) — {$label}");
        MaxService::sendMessage($shop, "Запись {$date} ({$booking->customer_name}) — {$label}");

        Log::info('Booking status updated via Telegram', [
            'booking_id' => $bookingId,
            'status'     => $newStatus,
        ]);
    }

    private function handleRatingCallback(string $callbackId, string $bookingId, string $scoreStr, int $chatId, ?int $messageId = null): void
    {
        $score = (int) $scoreStr;
        Log::info('[TG] rating callback', ['booking' => $bookingId, 'score' => $score, 'chat' => $chatId]);

        if ($score < 1 || $score > 5) {
            Log::warning('[TG] rating: invalid score', ['score' => $score]);
            $this->answerCallback($callbackId, 'Неверная оценка');
            return;
        }

        if (\Illuminate\Support\Facades\Cache::get("rated:{$bookingId}")) {
            Log::info('[TG] rating: already rated (cache hit)', ['booking' => $bookingId]);
            $this->answerCallback($callbackId, 'Вы уже оценили этот визит');
            return;
        }

        $schemas = \DB::select("
            SELECT schema_name FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            $s = $this->safeSchema($row->schema_name);

            $booking = \DB::selectOne("
                SELECT b.id, b.service_id, b.customer_id, b.customer_name, b.customer_phone,
                       b.master_id, p.name AS service_name
                FROM {$s}.bookings b
                LEFT JOIN {$s}.products p ON p.id = b.service_id
                WHERE b.id = ?
            ", [$bookingId]);

            if (!$booking) {
                continue;
            }

            Log::info('[TG] rating: booking found', ['schema' => $s, 'booking' => $bookingId, 'customer' => $booking->customer_phone]);

            $customer = \DB::selectOne(
                "SELECT telegram_chat_id FROM {$s}.customers WHERE phone = ?",
                [$booking->customer_phone]
            );

            Log::info('[TG] rating: customer lookup', [
                'found'              => (bool) $customer,
                'customer_chat_id'   => $customer ? $customer->telegram_chat_id : null,
                'callback_chat_id'   => $chatId,
                'match'              => $customer && (int) $customer->telegram_chat_id === $chatId,
            ]);

            if (!$customer || (int) $customer->telegram_chat_id !== $chatId) {
                Log::warning('[TG] rating: access denied');
                $this->answerCallback($callbackId, 'Нет доступа');
                return;
            }

            $reviewId = (string) \Illuminate\Support\Str::uuid();

            try {
                \DB::statement(
                    "INSERT INTO {$s}.reviews (id, product_id, customer_id, customer_name, rating, is_published, created_at) VALUES (?, ?, ?, ?, ?, false, NOW())",
                    [$reviewId, $booking->service_id, $booking->customer_id, $booking->customer_name, $score]
                );
            } catch (\Throwable $e) {
                Log::error('[TG] rating: DB insert FAILED', ['booking' => $bookingId, 'error' => $e->getMessage()]);
                $this->answerCallback($callbackId, 'Ошибка сохранения');
                return;
            }

            \Illuminate\Support\Facades\Cache::put("rated:{$bookingId}", true, now()->addDays(30));
            \Illuminate\Support\Facades\Cache::put("review_id:{$bookingId}", [
                'schema'        => $s,
                'id'            => $reviewId,
                'score'         => $score,
                'customer_name' => $booking->customer_name,
                'service_name'  => $booking->service_name ?? '—',
                'master_id'     => $booking->master_id,
            ], now()->addDays(30));
            $this->removeKeyboard($chatId, $messageId);

            try {
                MasterBotService::notifyAllOnReview(
                    $s, $booking->customer_name, $booking->service_name ?? '—',
                    $booking->master_id, $score, null
                );
            } catch (\Throwable) {}

            $stars = str_repeat('⭐', $score);
            $this->answerCallback($callbackId, "Спасибо за оценку! {$stars}");
            $reviewBtnMsgId = $this->sendReply($chatId, "Спасибо за вашу оценку {$stars}", [[
                ['text' => '✍️ Написать отзыв', 'callback_data' => "review:write:{$bookingId}"],
            ]]);
            if ($reviewBtnMsgId) {
                \Illuminate\Support\Facades\Cache::put("tg_review_btn_mid:{$bookingId}", ['chat_id' => $chatId, 'message_id' => $reviewBtnMsgId], now()->addDays(30));
            }
            Log::info('[TG] rating: saved OK', ['booking' => $bookingId, 'score' => $score]);
            return;
        }

        Log::warning('[TG] rating: booking not found in any schema', ['booking' => $bookingId]);
        $this->answerCallback($callbackId, 'Запись не найдена');
    }

    private function handleReviewCallback(string $callbackId, string $action, string $bookingId, int $chatId, ?int $messageId = null): void
    {
        if ($action !== 'write') {
            $this->answerCallback($callbackId, 'Неизвестное действие');
            return;
        }

        \Illuminate\Support\Facades\Cache::put("awaiting_review:tg:{$chatId}", $bookingId, now()->addMinutes(15));
        $this->answerCallback($callbackId, '');
        $this->sendReply($chatId, 'Напишите ваш отзыв:');
        Log::info('[TG] review: awaiting text', ['booking' => $bookingId, 'chat' => $chatId]);
    }

    private function handleClientAction(string $callbackId, string $action, string $bookingId, int $chatId, ?int $messageId = null): void
    {
        Log::info('[TG] client action', ['action' => $action, 'booking' => $bookingId, 'chat' => $chatId]);

        if ($action !== 'cancel') {
            Log::warning('[TG] client action: unknown action', ['action' => $action]);
            $this->answerCallback($callbackId, 'Неизвестное действие');
            return;
        }

        $schemas = \DB::select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'shop_%'");

        foreach ($schemas as $row) {
            $s = $this->safeSchema($row->schema_name);

            $booking = \DB::selectOne("
                SELECT b.id, b.status, b.customer_name, b.customer_phone, b.start_time
                FROM {$s}.bookings b WHERE b.id = ?
            ", [$bookingId]);

            if (!$booking) continue;

            Log::info('[TG] client cancel: booking found', ['schema' => $s, 'status' => $booking->status]);

            $customer = \DB::selectOne(
                "SELECT telegram_chat_id FROM {$s}.customers WHERE phone = ?",
                [$booking->customer_phone]
            );

            Log::info('[TG] client cancel: customer lookup', [
                'found'            => (bool) $customer,
                'customer_chat_id' => $customer ? $customer->telegram_chat_id : null,
                'callback_chat_id' => $chatId,
            ]);

            if (!$customer || (int) $customer->telegram_chat_id !== $chatId) {
                Log::warning('[TG] client cancel: access denied');
                $this->answerCallback($callbackId, 'Нет доступа');
                return;
            }

            if (!in_array($booking->status, ['pending', 'confirmed'])) {
                Log::info('[TG] client cancel: already non-cancellable', ['status' => $booking->status]);
                $this->answerCallback($callbackId, 'Запись уже нельзя отменить', true);
                return;
            }

            \DB::statement("UPDATE {$s}.bookings SET status = 'cancelled' WHERE id = ?", [$bookingId]);
            Log::info('[TG] client cancel: booking cancelled', ['booking' => $bookingId]);

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

            Log::info('[TG] client cancel: done', ['booking' => $bookingId]);
            return;
        }

        Log::warning('[TG] client cancel: booking not found in any schema', ['booking' => $bookingId]);
        $this->answerCallback($callbackId, 'Запись не найдена');
    }

    private function handleMasterBookingAction(string $callbackId, string $action, string $bookingId, int $chatId, ?int $messageId): void
    {
        $staff = \App\Models\ShopStaff::where('telegram_chat_id', $chatId)
            ->where('role', 'master')
            ->whereNotNull('master_id')
            ->first();

        if (!$staff) {
            $this->answerCallback($callbackId, 'Нет доступа');
            return;
        }

        $shop = Shop::find($staff->shop_id);
        if (!$shop) {
            $this->answerCallback($callbackId, 'Магазин не найден');
            return;
        }

        TenantService::setContext($shop);
        try {
            $booking = \App\Models\Booking::where('id', $bookingId)
                ->where('master_id', $staff->master_id)
                ->first();

            if (!$booking) {
                $this->answerCallback($callbackId, 'Запись не найдена');
                return;
            }

            if (!in_array($booking->status, ['pending', 'confirmed'])) {
                $this->answerCallback($callbackId, 'Статус уже изменён', true);
                return;
            }

            $newStatus = match($action) {
                'arrived' => 'completed',
                'noshow'  => 'no_show',
                default   => null,
            };

            if (!$newStatus) {
                $this->answerCallback($callbackId, 'Неизвестное действие');
                return;
            }

            $booking->load(['service']);
            $booking->update(['status' => $newStatus]);

            if ($newStatus === 'completed' && !$booking->rating_sent) {
                try { TelegramService::notifyRatingRequest($shop, $booking); } catch (\Throwable) {}
                try { MaxService::notifyRatingRequest($bookingId, $booking); } catch (\Throwable) {}
                $booking->update(['rating_sent' => true]);
            }

            $label = $newStatus === 'completed' ? '✅ Завершена' : '👻 Неявка';
            $this->answerCallback($callbackId, "Запись отмечена: {$label}");
            $this->removeKeyboard($chatId, $messageId);

            $date = \Carbon\Carbon::parse($booking->start_time)
                ->setTimezone($shop->timezone ?? 'Europe/Moscow')
                ->format('d.m H:i');
            $ownerMsg = "Запись {$date} (" . htmlspecialchars($booking->customer_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ") — {$label}";
            TelegramService::sendMessage(shop: $shop, text: $ownerMsg);
            MaxService::sendMessage($shop, $ownerMsg);

        } finally {
            TenantService::resetContext();
        }
    }

    // ── Telegram Bot API helpers ──────────────────────────────────────

    private function sendReply(int $chatId, string $text, ?array $keyboard = null): ?int
    {
        $botToken = config('services.telegram.bot_token');
        if (!$botToken) return null;

        $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];

        if ($keyboard) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
        }

        $response = Http::timeout(5)->post("https://api.telegram.org/bot{$botToken}/sendMessage", $payload);
        return $response->json('result.message_id');
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

    private function safeSchema(string $schema): string
    {
        if (!preg_match('/^shop_[a-z0-9_]+$/', $schema)) {
            throw new \RuntimeException('Invalid schema name');
        }
        return '"' . $schema . '"';
    }
}
