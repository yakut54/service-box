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

        return response()->json(['message' => 'MAX отключён']);
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
            $staff = MaxService::verifyMasterCode($code);
            if ($staff) {
                $this->tryConnectMaster($userId, $staff);
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

            $bookingId = MaxService::verifyCustomerCode($code);
            if ($bookingId) {
                $this->trySubscribeCustomer($userId, $bookingId);
                return;
            }

            $staff = MaxService::verifyMasterCode($code);
            if ($staff) {
                $this->tryConnectMaster($userId, $staff);
                return;
            }

            $this->tryConnect($userId, $code);
            return;
        }

        // ── Master slash commands ─────────────────────────────────────────
        $masterCmd = match(true) {
            in_array($text, ['/today', 'today']) => 'today',
            in_array($text, ['/week',  'week'])  => 'week',
            in_array($text, ['/stats', 'stats']) => 'stats',
            default => null,
        };

        if ($masterCmd !== null) {
            $ctx = MasterBotService::findByMaxUserId($userId);
            if ($ctx) {
                $responseText = match($masterCmd) {
                    'today' => MasterBotService::getScheduleText($ctx['schema'], $ctx['master_id'], $ctx['tz'], 'today', $ctx['hide_phone']),
                    'week'  => MasterBotService::getScheduleText($ctx['schema'], $ctx['master_id'], $ctx['tz'], 'week',  $ctx['hide_phone']),
                    'stats' => MasterBotService::getStatsText($ctx['schema'], $ctx['master_id'], $ctx['tz']),
                };
                MaxService::sendMasterMenu($userId, $responseText);
                return;
            }
        }

        // ── If master sends unrecognized text → show menu ─────────────────
        $masterCtx = MasterBotService::findByMaxUserId($userId);
        if ($masterCtx) {
            MaxService::sendMasterMenu($userId);
            return;
        }

        $pendingBookingId = \Illuminate\Support\Facades\Cache::get("awaiting_review:max:{$userId}");
        if ($pendingBookingId) {
            \Illuminate\Support\Facades\Cache::forget("awaiting_review:max:{$userId}");
            $cached = \Illuminate\Support\Facades\Cache::get("review_id:{$pendingBookingId}");
            if ($cached) {
                \DB::statement(
                    "UPDATE {$cached['schema']}.reviews SET text = ? WHERE id = ?",
                    [$text, $cached['id']]
                );
                Log::info('[MAX] review: text saved', ['booking' => $pendingBookingId]);
                if (!empty($cached['id'])) {
                    try {
                        MasterBotService::updateOwnerReviewWithText(
                            $cached['id'],
                            $cached['schema'],
                            $cached['customer_name'] ?? '—',
                            $cached['service_name']  ?? '—',
                            $cached['score']         ?? 5,
                            $text
                        );
                    } catch (\Throwable) {}
                }
            }
            $reviewBtn = \Illuminate\Support\Facades\Cache::get("max_review_btn_mid:{$pendingBookingId}");
            if ($reviewBtn) MaxService::removeButtons((int) $reviewBtn['user_id'], $reviewBtn['mid']);
            MaxService::sendRaw($userId, '✅ Спасибо! Ваш отзыв отправлен на модерацию.');
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

    private function tryConnectMaster(int $userId, \App\Models\ShopStaff $staff): void
    {
        MaxService::connectMaster($staff, $userId);
        MaxService::sendMasterMenu(
            $userId,
            "✅ <b>Готово!</b> Теперь вы будете получать уведомления о своих записях прямо сюда.\n\nИспользуйте кнопки ниже 👇"
        );
    }

    private function handleCallback(array $update): void
    {
        $callbackId = $update['callback']['callback_id'] ?? null;
        $payload    = $update['callback']['payload'] ?? '';
        $userId     = $update['callback']['user']['user_id'] ?? null;
        $mid        = $update['callback']['message']['body']['mid'] ?? null;

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

        if ($entityType === 'master_booking') {
            $this->handleMasterBookingAction($callbackId, $action, $entityId, $userId);
            return;
        }

        if ($entityType === 'master_menu') {
            $this->handleMasterMenuCallback($callbackId, $action, $userId, $mid);
            return;
        }

        if ($entityType === 'review_pub') {
            // $action = reviewId, $entityId = '1' (publish) or '0' (hide)
            $this->handleReviewPub($callbackId, $action, (bool)(int)$entityId, $userId, $mid);
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

        $validFrom = match($action) {
            'confirm', 'cancel'  => ['pending'],
            'complete', 'noshow' => ['confirmed'],
            default              => [],
        };

        if (!in_array($booking->status, $validFrom)) {
            MaxService::answerCallback($cbId, 'Статус уже изменён');
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
            MaxService::answerCallback($cbId, 'Неизвестное действие');
            return;
        }

        $booking->load(['service', 'master']);
        $booking->update(['status' => $newStatus]);

        MaxService::notifyCustomerStatus($bookingId, $newStatus, $booking, $shop->timezone ?? 'Europe/Moscow');
        try { TelegramService::notifyBookingStatusToCustomer($shop, $booking, $newStatus); } catch (\Throwable) {}

        if ($newStatus === 'completed' && !$booking->rating_sent) {
            try { TelegramService::notifyRatingRequest($shop, $booking); } catch (\Throwable) {}
            try { MaxService::notifyRatingRequest($bookingId, $booking); } catch (\Throwable) {}
            $booking->update(['rating_sent' => true]);
        }

        $label = match($newStatus) {
            'confirmed' => '✅ Подтверждена',
            'cancelled' => '❌ Отменена',
            'completed' => '✅ Завершена',
            'no_show'   => '👻 Неявка',
            default     => $newStatus,
        };

        MaxService::answerCallback($cbId, "Запись {$label}");
        MaxService::removeButtonsByEntity('booking', $bookingId);
        TelegramService::removeKeyboardByEntity('Booking', $bookingId);
        $cached = \Illuminate\Support\Facades\Cache::get("max_mid:completion:{$bookingId}");
        if ($cached) MaxService::removeButtons((int) $cached['user_id'], $cached['mid']);

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
            $s = $this->safeSchema($row->schema_name);

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
            $s = $this->safeSchema($row->schema_name);

            $booking = \DB::selectOne("
                SELECT b.id, b.service_id, b.customer_id, b.customer_name,
                       b.master_id, p.name AS service_name
                FROM {$s}.bookings b
                LEFT JOIN {$s}.products p ON p.id = b.service_id
                WHERE b.id = ?
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
                \DB::statement(
                    "INSERT INTO {$s}.reviews (id, product_id, customer_id, customer_name, rating, is_published, created_at) VALUES (?, ?, ?, ?, ?, false, NOW())",
                    [$reviewId, $booking->service_id, $booking->customer_id, $booking->customer_name, $score]
                );
            } catch (\Throwable $e) {
                Log::error('[MAX] rating: DB insert FAILED', ['booking' => $bookingId, 'error' => $e->getMessage()]);
                MaxService::answerCallback($cbId, 'Ошибка сохранения');
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

            try {
                MasterBotService::notifyAllOnReview(
                    $s, $booking->customer_name, $booking->service_name ?? '—',
                    $booking->master_id, $score, null, $reviewId
                );
            } catch (\Throwable) {}

            $cached = \Illuminate\Support\Facades\Cache::get("max_rating_mid:{$bookingId}");
            Log::info('[MAX] rating: mid cache', ['found' => (bool) $cached, 'mid' => $cached['mid'] ?? null]);
            if ($cached) {
                MaxService::removeButtons((int) $cached['user_id'], $cached['mid']);
            }

            $stars = str_repeat('⭐', $score);
            MaxService::answerCallback($cbId, "Спасибо за оценку! {$stars}");
            $reviewBtnMid = MaxService::sendRaw($userId, "Спасибо за оценку {$stars}", [[
                ['type' => 'callback', 'text' => '✍️ Написать отзыв', 'payload' => "review:write:{$bookingId}"],
            ]]);
            if ($reviewBtnMid) {
                \Illuminate\Support\Facades\Cache::put("max_review_btn_mid:{$bookingId}", ['user_id' => $userId, 'mid' => $reviewBtnMid], now()->addDays(30));
            }
            Log::info('[MAX] rating: saved OK', ['booking' => $bookingId, 'score' => $score]);
            return;
        }

        Log::warning('[MAX] rating: booking not found in any schema', ['booking' => $bookingId]);
        MaxService::answerCallback($cbId, 'Запись не найдена');
    }

    private function handleMasterBookingAction(string $cbId, string $action, string $bookingId, int $userId): void
    {
        $staff = \App\Models\ShopStaff::where('max_user_id', $userId)
            ->where('role', 'master')
            ->whereNotNull('master_id')
            ->first();

        if (!$staff) {
            MaxService::answerCallback($cbId, 'Нет доступа');
            return;
        }

        $shop = Shop::find($staff->shop_id);
        if (!$shop) {
            MaxService::answerCallback($cbId, 'Магазин не найден');
            return;
        }

        TenantService::setContext($shop);
        try {
            $booking = \App\Models\Booking::where('id', $bookingId)
                ->where('master_id', $staff->master_id)
                ->first();

            if (!$booking) {
                MaxService::answerCallback($cbId, 'Запись не найдена');
                return;
            }

            if (!in_array($booking->status, ['pending', 'confirmed'])) {
                MaxService::answerCallback($cbId, 'Статус уже изменён');
                return;
            }

            $newStatus = match($action) {
                'arrived' => 'completed',
                'noshow'  => 'no_show',
                default   => null,
            };

            if (!$newStatus) {
                MaxService::answerCallback($cbId, 'Неизвестное действие');
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
            MaxService::answerCallback($cbId, "Запись отмечена: {$label}");

            // Убираем кнопки с напоминания
            $cached = \Illuminate\Support\Facades\Cache::get("max_master_reminder_mid:{$bookingId}");
            if ($cached) MaxService::removeButtons((int) $cached['user_id'], $cached['mid']);

            $date = \Carbon\Carbon::parse($booking->start_time)
                ->setTimezone($shop->timezone ?? 'Europe/Moscow')
                ->format('d.m H:i');

            $ownerMsg = "Запись {$date} (" . htmlspecialchars($booking->customer_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ") — {$label}";
            MaxService::sendMessage($shop, $ownerMsg);
            TelegramService::sendMessage(shop: $shop, text: $ownerMsg);

            Log::info('[MAX] master booking action', ['booking' => $bookingId, 'status' => $newStatus]);

        } finally {
            TenantService::resetContext();
        }
    }

    private function handleMasterMenuCallback(string $cbId, string $action, int $userId, ?string $mid = null): void
    {
        $ctx = MasterBotService::findByMaxUserId($userId);

        if (!$ctx) {
            MaxService::answerCallback($cbId, 'Мастер не найден');
            return;
        }

        $responseText = match ($action) {
            'today' => MasterBotService::getScheduleText($ctx['schema'], $ctx['master_id'], $ctx['tz'], 'today', $ctx['hide_phone']),
            'week'  => MasterBotService::getScheduleText($ctx['schema'], $ctx['master_id'], $ctx['tz'], 'week',  $ctx['hide_phone']),
            'stats' => MasterBotService::getStatsText($ctx['schema'], $ctx['master_id'], $ctx['tz']),
            default => null,
        };

        if ($responseText === null) {
            MaxService::answerCallback($cbId, 'Неизвестная команда');
            return;
        }

        MaxService::answerCallback($cbId, '');
        $cachedMid = \Illuminate\Support\Facades\Cache::get("max_master_menu_mid:{$userId}");
        MaxService::removeButtons($userId, $cachedMid ?? $mid);
        MaxService::sendMasterMenu($userId, $responseText);

        Log::info('[MAX] master_menu callback handled', ['action' => $action, 'user_id' => $userId]);
    }

    private function handleReviewPub(string $callbackId, string $reviewId, bool $publish, int $userId, ?string $mid): void
    {
        $schemas = \DB::select("SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'shop_%'");

        foreach ($schemas as $row) {
            $s = $this->safeSchema($row->schema_name);
            $review = \DB::selectOne("SELECT id FROM {$s}.reviews WHERE id = ?", [$reviewId]);
            if (!$review) continue;

            \DB::statement("UPDATE {$s}.reviews SET is_published = ? WHERE id = ?", [$publish, $reviewId]);

            MaxService::answerCallback($callbackId, $publish ? '✅ Опубликовано' : '🙈 Скрыто');

            // Update MAX button in this message (cross-bot: same mid from callback)
            if ($mid) {
                MaxService::updateReviewButtons($userId, $mid, $reviewId, $publish);
            }

            // Cross-bot sync: update TG button
            $tgCached = \Illuminate\Support\Facades\Cache::get("tg_owner_review:{$reviewId}");
            if ($tgCached) {
                TelegramService::editReviewButtons($tgCached['chat_id'], $tgCached['message_id'], $reviewId, $publish);
            }

            Log::info('[MAX] review_pub', ['review' => $reviewId, 'published' => $publish]);
            return;
        }

        MaxService::answerCallback($callbackId, 'Отзыв не найден');
    }

    private function safeSchema(string $schema): string
    {
        if (!preg_match('/^shop_[a-z0-9_]+$/', $schema)) {
            throw new \RuntimeException('Invalid schema name');
        }
        return '"' . $schema . '"';
    }
}
