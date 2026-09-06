<?php

namespace App\Services;

use App\Models\Shop;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MaxService
{
    private static function api(): string
    {
        return config('services.max.api_url', 'https://platform-api.max.ru');
    }

    private static function token(): ?string
    {
        return config('services.max.bot_token');
    }

    // ── Core send ─────────────────────────────────────────────────────────

    public static function sendMessage(Shop $shop, string $text, ?array $buttons = null): ?string
    {
        if (!$shop->max_bot_connected || !$shop->max_chat_id) {
            return null;
        }

        $token = self::token();
        if (!$token) {
            Log::error('MAX bot token not configured');
            return null;
        }

        $body = ['text' => $text, 'format' => 'html'];

        if ($buttons) {
            $body['attachments'] = [[
                'type'    => 'inline_keyboard',
                'payload' => ['buttons' => $buttons],
            ]];
        }

        $response = Http::timeout(5)
            ->withHeaders(['Authorization' => $token])
            ->post(self::api() . '/messages?user_id=' . $shop->max_chat_id, $body);

        $mid = $response->json('message.body.mid')
            ?? $response->json('body.mid')
            ?? $response->json('mid')
            ?? null;

        Log::info('MAX sendMessage response', ['mid' => $mid, 'status' => $response->status()]);

        return $mid;
    }

    public static function sendRaw(int $chatId, string $text, ?array $buttons = null): ?string
    {
        $token = self::token();
        if (!$token) return null;

        $body = ['text' => $text, 'format' => 'html'];

        if ($buttons) {
            $body['attachments'] = [[
                'type'    => 'inline_keyboard',
                'payload' => ['buttons' => $buttons],
            ]];
        }

        $response = Http::timeout(5)
            ->withHeaders(['Authorization' => $token])
            ->post(self::api() . '/messages?user_id=' . $chatId, $body);

        return $response->json('message.body.mid')
            ?? $response->json('body.mid')
            ?? $response->json('mid')
            ?? null;
    }

    public static function answerCallback(string $callbackId, string $text): void
    {
        $token = self::token();
        if (!$token) return;

        Http::timeout(5)
            ->withHeaders(['Authorization' => $token])
            ->post(self::api() . '/answers', [
                'callback' => ['callback_id' => $callbackId],
                'messages' => [['text' => $text]],
            ]);
    }

    public static function removeButtons(int $userId, ?string $mid): void
    {
        if (!$mid) {
            Log::warning('MAX removeButtons: mid is null');
            return;
        }

        $token = self::token();
        if (!$token) return;

        try {
            $response = Http::timeout(5)
                ->withHeaders(['Authorization' => $token])
                ->put(self::api() . '/messages?message_id=' . urlencode($mid), [
                    'attachments' => [],
                    'notify'      => false,
                ]);
            Log::info('MAX removeButtons response', ['status' => $response->status(), 'body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::error('MAX removeButtons error', ['error' => $e->getMessage()]);
        }
    }

    // ── Owner notifications ───────────────────────────────────────────────

    public static function notifyNewOrder(Shop $shop, $order): void
    {
        if (!$shop->max_bot_connected) return;

        $total = Money::rubles($order->total_price);

        $deliveryLabels = [
            'pickup'  => 'Самовывоз',
            'courier' => 'Курьер',
            'postal'  => 'Почта / СДЭК',
        ];

        $text  = "🛒 <b>Новый заказ!</b>\n\n";
        $text .= "🔖 №" . substr($order->id, 0, 8) . "\n";
        $text .= "💰 <b>{$total} ₽</b>\n\n";
        $text .= "Клиент: " . self::esc($order->customer_name) . "\n";
        $text .= "Телефон: " . self::esc($order->customer_phone);

        $method = $order->delivery_method ?? null;
        if ($method) {
            $label = $deliveryLabels[$method] ?? self::esc($method);
            $icon  = $method === 'pickup' ? '🏪' : '📦';
            $deliveryLine = "\n{$icon} {$label}";
            if ($order->delivery_price > 0) {
                $deliveryLine .= ' — ' . Money::rubles($order->delivery_price) . ' ₽';
            }
            $text .= $deliveryLine;
        }

        $addr = $order->shipping_address;
        if ($addr && in_array($method, ['courier', 'postal'])) {
            $street = implode(', ', array_filter([$addr['city'] ?? null, $addr['street'] ?? null]));
            $house  = implode(', ', array_filter([
                !empty($addr['building'])  ? 'д. '  . $addr['building']  : null,
                !empty($addr['apartment']) ? 'кв. ' . $addr['apartment'] : null,
            ]));
            if ($street) $text .= "\n" . self::esc($street);
            if ($house)  $text .= "\n" . self::esc($house);
            if (!empty($addr['postal_code'])) $text .= "\n" . self::esc($addr['postal_code']);
        }

        if (!empty($order->notes)) {
            $text .= "\n\n💬 " . self::esc($order->notes);
        }

        $buttons = [[
            ['type' => 'callback', 'text' => '✅ Подтвердить', 'payload' => "order:confirm:{$order->id}"],
            ['type' => 'callback', 'text' => '❌ Отменить',    'payload' => "order:cancel:{$order->id}"],
        ]];

        try {
            $mid = self::sendMessage($shop, $text, $buttons);
            if ($mid) {
                Cache::put("max_mid:order:{$order->id}", ['user_id' => $shop->max_chat_id, 'mid' => $mid], now()->addDays(7));
            }
        } catch (\Throwable $e) {
            Log::warning('MAX notify order failed', ['error' => $e->getMessage()]);
        }
    }

    public static function notifyNewBooking(Shop $shop, $booking): void
    {
        if (!$shop->max_bot_connected) return;

        $tz      = $shop->timezone ?? 'Europe/Moscow';
        $dt      = \Carbon\Carbon::parse($booking->start_time)->setTimezone($tz)->locale('ru');
        $date    = $dt->translatedFormat('j M, D');
        $time    = $dt->format('H:i');
        $service = $booking->service?->name ?? '—';
        $master  = $booking->master?->name;
        $price   = $booking->service?->price
            ? '💰 ' . Money::rubles($booking->service->price) . " ₽\n"
            : '';

        $text  = "📅 <b>Новая запись!</b>\n\n";
        $text .= "🕐 <b>{$date} в {$time}</b>\n";
        $text .= "📋 " . self::esc($service) . "\n";
        if ($master) $text .= "👤 " . self::esc($master) . "\n";
        $text .= $price;
        $text .= "\nКлиент: " . self::esc($booking->customer_name) . "\n";
        $text .= "Телефон: " . self::esc($booking->customer_phone);
        if (!empty($booking->notes)) $text .= "\n\n💬 " . self::esc($booking->notes);

        $buttons = [[
            ['type' => 'callback', 'text' => '✅ Подтвердить', 'payload' => "booking:confirm:{$booking->id}"],
            ['type' => 'callback', 'text' => '❌ Отменить',    'payload' => "booking:cancel:{$booking->id}"],
        ]];

        try {
            $mid = self::sendMessage($shop, $text, $buttons);
            if ($mid) {
                Cache::put("max_mid:booking:{$booking->id}", ['user_id' => $shop->max_chat_id, 'mid' => $mid], now()->addDays(7));
            }
        } catch (\Throwable $e) {
            Log::warning('MAX notify booking failed', ['error' => $e->getMessage()]);
        }
    }

    public static function notifyOwnerCompletionRequest(Shop $shop, object $booking): void
    {
        $tz      = $shop->timezone ?? 'Europe/Moscow';
        $start   = \Carbon\Carbon::parse($booking->start_time)->setTimezone($tz)->format('H:i');
        $end     = \Carbon\Carbon::parse($booking->end_time)->setTimezone($tz)->format('H:i');
        $service = $booking->service_name ?? '—';
        $name    = $booking->customer_name;

        $text = "📋 " . self::esc($service) . " — {$start}–{$end} (" . self::esc($name) . ")\nКак прошло?";

        $buttons = [[
            ['type' => 'callback', 'text' => '✅ Завершить', 'payload' => "booking:complete:{$booking->id}"],
            ['type' => 'callback', 'text' => '👻 Неявка',   'payload' => "booking:noshow:{$booking->id}"],
        ]];

        $mid = self::sendMessage($shop, $text, $buttons);
        if ($mid) {
            Cache::put("max_mid:completion:{$booking->id}", ['user_id' => $shop->max_chat_id, 'mid' => $mid], now()->addDays(3));
        }
    }

    public static function notifyOwnerBookingStatus(Shop $shop, $booking, string $status): void
    {
        $label = match($status) {
            'confirmed' => '✅ Подтверждена',
            'cancelled' => '❌ Отменена',
            'completed' => '✅ Завершена',
            'no_show'   => '👻 Неявка',
            default     => null,
        };
        if (!$label) return;

        $tz   = $shop->timezone ?? 'Europe/Moscow';
        $dt   = \Carbon\Carbon::parse($booking->start_time)->setTimezone($tz);
        $date = $dt->format('d.m');
        $time = $dt->format('H:i');
        $name = $booking->customer_name ?? $booking->customer_phone;

        self::sendMessage($shop, "Запись {$date} {$time} (" . self::esc($name) . ") — {$label}");
    }

    /**
     * Покупатель не подтвердил доплату за 3 часа — заказ переведён в
     * needs_attention (см. CheckSurchargeDeadline). Владельцу — в общий инбокс.
     */
    public static function notifyOwnerSurchargeExpired(Shop $shop, $order): void
    {
        $amount = Money::rubles($order->surcharge_amount);
        $name   = $order->customer_name ?? $order->customer_phone;

        $text = "⚠️ <b>Не оплачена доплата за заказ №" . substr($order->id, 0, 8) . "</b>\n\n"
              . "Клиент: " . self::esc($name) . "\n"
              . "Сумма доплаты: {$amount} ₽\n\n"
              . "Срок истёк, заказ требует внимания.";

        self::sendMessage($shop, $text);
    }

    public static function removeButtonsByEntity(string $type, string $entityId): void
    {
        $cached = Cache::get("max_mid:{$type}:{$entityId}");
        if (!$cached) return;

        self::removeButtons((int) $cached['user_id'], $cached['mid']);
    }

    // ── Customer subscription flow ────────────────────────────────────────

    public static function generateCustomerCode(string $bookingId): string
    {
        $code = strtoupper(substr(md5($bookingId . microtime()), 0, 6));
        Cache::put("max_ccode:{$code}", $bookingId, now()->addMinutes(30));
        return $code;
    }

    public static function verifyCustomerCode(string $code): ?string
    {
        $bookingId = Cache::get("max_ccode:{$code}");
        if (!$bookingId) return null;
        Cache::forget("max_ccode:{$code}");
        return $bookingId;
    }

    public static function subscribeCustomer(string $bookingId, int $userId): void
    {
        $found = self::findCustomerByBooking($bookingId);
        if (!$found) return;

        DB::statement(
            "UPDATE \"{$found['schema']}\".customers SET max_user_id = ? WHERE id = ?",
            [$userId, $found['customer_id']]
        );
    }

    public static function getCustomerUserId(string $bookingId): ?int
    {
        $found = self::findCustomerByBooking($bookingId);
        return $found ? ($found['max_user_id'] ?? null) : null;
    }

    /**
     * $bookingId на самом деле — id любой сущности, для которой был вызван
     * generateCustomerCode (booking или order, общий namespace кодов — см.
     * notifySurchargeRequest ниже, доплата за перевзвешенный заказ).
     */
    private static function findCustomerByBooking(string $bookingId): ?array
    {
        $schemas = DB::select("
            SELECT schema_name FROM information_schema.schemata
            WHERE schema_name LIKE 'shop_%'
        ");

        foreach ($schemas as $row) {
            $s = $row->schema_name;
            $result = DB::selectOne("
                SELECT c.id, c.max_user_id
                FROM \"{$s}\".bookings b
                JOIN \"{$s}\".customers c ON c.id = b.customer_id
                WHERE b.id = ?
            ", [$bookingId]);

            if (!$result) {
                $result = DB::selectOne("
                    SELECT c.id, c.max_user_id
                    FROM \"{$s}\".orders o
                    JOIN \"{$s}\".customers c ON c.id = o.customer_id
                    WHERE o.id = ?
                ", [$bookingId]);
            }

            if ($result) {
                return [
                    'schema'      => $s,
                    'customer_id' => $result->id,
                    'max_user_id' => $result->max_user_id,
                ];
            }
        }

        return null;
    }

    public static function notifyRatingRequest(string $bookingId, object $booking): void
    {
        $userId = self::getCustomerUserId($bookingId);
        if (!$userId) return;

        $service = $booking->service_name ?? $booking->service?->name ?? '—';

        $text  = "⭐ <b>Как прошёл визит?</b>\n\n";
        $text .= "📋 " . self::esc($service) . "\n";
        $text .= "\nОцените, пожалуйста, услугу:";

        $buttons = [[
            ['type' => 'callback', 'text' => '1 ⭐', 'payload' => "rate:{$bookingId}:1"],
            ['type' => 'callback', 'text' => '2 ⭐', 'payload' => "rate:{$bookingId}:2"],
            ['type' => 'callback', 'text' => '3 ⭐', 'payload' => "rate:{$bookingId}:3"],
            ['type' => 'callback', 'text' => '4 ⭐', 'payload' => "rate:{$bookingId}:4"],
            ['type' => 'callback', 'text' => '5 ⭐', 'payload' => "rate:{$bookingId}:5"],
        ]];

        $mid = self::sendRaw($userId, $text, $buttons);
        if ($mid) {
            Cache::put("max_rating_mid:{$bookingId}", ['user_id' => $userId, 'mid' => $mid], now()->addDays(2));
        }
    }

    public static function notifyBookingReminder(string $bookingId, object $booking, string $type, string $timezone): void
    {
        $userId = self::getCustomerUserId($bookingId);
        if (!$userId) return;

        $label   = $type === '24h' ? 'Завтра' : 'Через 2 часа';
        $dt      = \Carbon\Carbon::parse($booking->start_time)->setTimezone($timezone)->locale('ru');
        $date    = $dt->translatedFormat('j M, D');
        $time    = $dt->format('H:i');
        $service = $booking->service_name ?? '—';
        $master  = $booking->master_name  ?? null;

        $text  = "⏰ <b>Напоминание о записи</b>\n\n";
        $text .= "🕐 <b>{$label}, {$date} в {$time}</b>\n";
        $text .= "📋 " . self::esc($service) . "\n";
        if ($master) $text .= "👤 " . self::esc($master) . "\n";

        $buttons = [[
            ['type' => 'callback', 'text' => '❌ Отменить запись', 'payload' => "client:cancel:{$bookingId}"],
        ]];

        $mid = self::sendRaw($userId, $text, $buttons);
        if ($mid) {
            Cache::put("max_reminder_cancel_mid:{$bookingId}", ['user_id' => $userId, 'mid' => $mid], now()->addDays(2));
        }
    }

    public static function notifyCustomerStatus(string $bookingId, string $status, $booking, string $timezone): void
    {
        $userId = self::getCustomerUserId($bookingId);
        if (!$userId) return;

        $label = match($status) {
            'confirmed'  => '✅ Ваша запись <b>подтверждена</b>',
            'cancelled'  => '❌ Ваша запись <b>отменена</b>',
            'completed'  => '✅ Визит <b>завершён</b>. Спасибо!',
            default      => null,
        };
        if (!$label) return;

        $dt      = \Carbon\Carbon::parse($booking->start_time)->setTimezone($timezone)->locale('ru');
        $date    = $dt->translatedFormat('j M');
        $time    = $dt->format('H:i');
        $service = $booking->service?->name ?? '';

        $text = "{$label}\n\n📋 " . self::esc($service) . "\n🕐 {$date} в {$time}";

        $buttons = null;
        if ($status === 'confirmed') {
            $buttons = [[
                ['type' => 'callback', 'text' => '❌ Отменить запись', 'payload' => "client:cancel:{$bookingId}"],
            ]];
        }

        // Удаляем кнопку "Отменить запись" из предыдущего сообщения
        if (in_array($status, ['completed', 'cancelled'])) {
            foreach (['max_cust_cancel_mid', 'max_reminder_cancel_mid'] as $key) {
                $cached = Cache::get("{$key}:{$bookingId}");
                if ($cached) self::removeButtons((int) $cached['user_id'], $cached['mid']);
            }
        }

        try {
            $mid = self::sendRaw($userId, $text, $buttons);
            if ($mid && $status === 'confirmed') {
                Cache::put("max_cust_cancel_mid:{$bookingId}", ['user_id' => $userId, 'mid' => $mid], now()->addDays(7));
            }
        } catch (\Throwable) {}
    }

    /**
     * Уведомление о доплате за перевзвешенный заказ (см.
     * OrderReweighService::finalizeOrder). Работает только если покупатель
     * уже привязал MAX через код, выданный при оформлении заказа
     * (см. OrderController::store — max_code генерируется только для
     * заказов с товаром weight_variable).
     */
    public static function notifySurchargeRequest(Shop $shop, $order): void
    {
        $userId = self::getCustomerUserId($order->id);
        if (!$userId) return;

        $tz       = $shop->timezone ?? 'Europe/Moscow';
        $deadline = \Carbon\Carbon::parse($order->surcharge_deadline_at)->setTimezone($tz)->locale('ru');
        $amount   = Money::rubles($order->surcharge_amount);

        $text  = "⚠️ <b>Требуется доплата за заказ</b>\n\n";
        $text .= "Фактический вес товара оказался больше заявленного при оформлении.\n";
        $text .= "Сумма доплаты: <b>{$amount} ₽</b>\n";
        $text .= "Подтвердите до <b>" . $deadline->translatedFormat('j M, H:i') . "</b>, иначе заказ будет отменён.";

        if ($order->surcharge_payment_url) {
            $text .= "\n\n🔗 " . $order->surcharge_payment_url;
        }

        self::sendRaw($userId, $text);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // ── Connection flow ───────────────────────────────────────────────────

    public static function generateConnectionCode(Shop $shop): string
    {
        $code = strtoupper(substr(md5($shop->id . microtime()), 0, 6));
        Cache::put("max_code:{$code}", $shop->id, now()->addMinutes(10));
        return $code;
    }

    public static function verifyConnectionCode(string $code): ?Shop
    {
        $shopId = Cache::get("max_code:{$code}");
        if (!$shopId) return null;
        Cache::forget("max_code:{$code}");
        return Shop::find($shopId);
    }

    public static function connectMax(Shop $shop, int $userId): void
    {
        $shop->update([
            'max_chat_id'       => $userId,
            'max_bot_connected' => true,
        ]);

        Log::info('MAX connected', ['shop_id' => $shop->id, 'user_id' => $userId]);
    }

    // ── Master messenger methods ──────────────────────────────────────────

    public static function generateMasterCode(\App\Models\ShopStaff $staff): string
    {
        $code = strtoupper(substr(md5($staff->id . microtime()), 0, 6));
        Cache::put("max_master_code:{$code}", $staff->id, now()->addMinutes(10));
        return $code;
    }

    public static function verifyMasterCode(string $code): ?\App\Models\ShopStaff
    {
        $staffId = Cache::get("max_master_code:{$code}");
        if (!$staffId) return null;
        Cache::forget("max_master_code:{$code}");
        return \App\Models\ShopStaff::find($staffId);
    }

    public static function connectMaster(\App\Models\ShopStaff $staff, int $userId): void
    {
        $staff->update(['max_user_id' => $userId]);
        Log::info('MAX master connected', ['staff_id' => $staff->id, 'user_id' => $userId]);
    }

    /**
     * Send (or re-send) the master navigation menu with callback buttons.
     * Caches the mid so the next call can remove the previous menu's buttons.
     */
    public static function sendMasterMenu(int $userId, string $intro = ''): void
    {
        $text = $intro ?: '📋 Выберите действие:';

        $buttons = [[
            ['type' => 'callback', 'text' => '📅 Сегодня', 'payload' => 'master_menu:today:0'],
            ['type' => 'callback', 'text' => '📅 Неделя',  'payload' => 'master_menu:week:0'],
        ],[
            ['type' => 'callback', 'text' => '📊 Статистика', 'payload' => 'master_menu:stats:0'],
        ]];

        $mid = self::sendRaw($userId, $text, $buttons);
        if ($mid) {
            Cache::put("max_master_menu_mid:{$userId}", $mid, now()->addDays(30));
        }
    }

    public static function notifyMasterNewBooking(int $userId, $booking, string $timezone, bool $hidePhone = false): void
    {
        $dt      = \Carbon\Carbon::parse($booking->start_time)->setTimezone($timezone)->locale('ru');
        $date    = $dt->translatedFormat('j M, D');
        $time    = $dt->format('H:i');
        $service = $booking->service?->name ?? '—';

        $text  = "📅 <b>Новая запись!</b>\n\n";
        $text .= "🕐 <b>{$date} в {$time}</b>\n";
        $text .= "📋 " . self::esc($service) . "\n\n";
        $text .= "👤 " . self::esc($booking->customer_name);
        if (!$hidePhone && !empty($booking->customer_phone)) {
            $text .= "\n📞 " . self::esc($booking->customer_phone);
        }
        if (!empty($booking->notes)) $text .= "\n\n💬 " . self::esc($booking->notes);

        try {
            self::sendRaw($userId, $text);
        } catch (\Throwable $e) {
            Log::warning('MAX notify master booking failed', ['error' => $e->getMessage()]);
        }
    }

    public static function notifyMasterBookingStatus(int $userId, $booking, string $status, string $timezone): void
    {
        $label = match($status) {
            'cancelled' => '❌ Запись отменена',
            default     => null,
        };
        if (!$label) return;

        $dt   = \Carbon\Carbon::parse($booking->start_time)->setTimezone($timezone);
        $date = $dt->format('d.m H:i');

        try {
            self::sendRaw($userId, "{$label}\n🕐 {$date} — " . self::esc($booking->customer_name));
        } catch (\Throwable) {}
    }

    public static function notifyMasterReminder(int $userId, object $booking, string $type, string $timezone): void
    {
        $label   = $type === '24h' ? 'Завтра' : 'Через 2 часа';
        $dt      = \Carbon\Carbon::parse($booking->start_time)->setTimezone($timezone)->locale('ru');
        $date    = $dt->translatedFormat('j M, D');
        $time    = $dt->format('H:i');
        $service = $booking->service_name ?? '—';

        $text  = "⏰ <b>Напоминание</b>\n\n";
        $text .= "🕐 <b>{$label}, {$date} в {$time}</b>\n";
        $text .= "📋 " . self::esc($service) . "\n";
        $text .= "👤 " . self::esc($booking->customer_name);

        try {
            if ($type === '2h') {
                $buttons = [[
                    ['type' => 'callback', 'text' => '✅ Пришёл',    'payload' => "master_booking:arrived:{$booking->id}"],
                    ['type' => 'callback', 'text' => '👻 Не пришёл', 'payload' => "master_booking:noshow:{$booking->id}"],
                ]];
                $mid = self::sendRaw($userId, $text, $buttons);
                if ($mid) {
                    Cache::put("max_master_reminder_mid:{$booking->id}", ['user_id' => $userId, 'mid' => $mid], now()->addDays(2));
                }
            } else {
                self::sendRaw($userId, $text);
            }
        } catch (\Throwable) {}
    }

    // ── Review notifications ──────────────────────────────────────────────

    public static function notifyOwnerReview(Shop $shop, string $customerName, string $serviceName, int $score, ?string $text, ?string $reviewId = null): void
    {
        if (!$shop->max_bot_connected || !$shop->max_chat_id) return;

        $stars = str_repeat('⭐', $score);
        $msg   = "{$stars} <b>Новый отзыв!</b>\n\n";
        $msg  .= "👤 " . self::esc($customerName) . "\n";
        $msg  .= "💅 " . self::esc($serviceName);
        if ($text) $msg .= "\n💬 «" . self::esc($text) . "»";

        $buttons = $reviewId ? [[
            ['type' => 'callback', 'text' => '✅ Опубликовать', 'payload' => "review_pub:{$reviewId}:1"],
        ]] : null;

        try {
            $mid = self::sendRaw($shop->max_chat_id, $msg, $buttons);
            if ($reviewId && $mid) {
                Cache::put(
                    "max_owner_review:{$reviewId}",
                    ['user_id' => $shop->max_chat_id, 'mid' => $mid],
                    now()->addDays(30)
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[MAX] notifyOwnerReview failed', ['error' => $e->getMessage()]);
        }
    }

    public static function editOwnerReviewWithText(int $userId, string $mid, string $customerName, string $serviceName, int $score, string $text, string $reviewId, bool $isPublished): void
    {
        $token = self::token();
        if (!$token) return;

        $stars  = str_repeat('⭐', $score);
        $msg    = "{$stars} <b>Новый отзыв!</b>\n\n";
        $msg   .= "👤 " . self::esc($customerName) . "\n";
        $msg   .= "💅 " . self::esc($serviceName) . "\n";
        $msg   .= "💬 «" . self::esc($text) . "»";

        $btn = $isPublished
            ? ['type' => 'callback', 'text' => '🙈 Скрыть',       'payload' => "review_pub:{$reviewId}:0"]
            : ['type' => 'callback', 'text' => '✅ Опубликовать', 'payload' => "review_pub:{$reviewId}:1"];

        try {
            Http::timeout(5)
                ->withHeaders(['Authorization' => $token])
                ->put(self::api() . '/messages?message_id=' . urlencode($mid), [
                    'text'        => $msg,
                    'format'      => 'html',
                    'attachments' => [[
                        'type'    => 'inline_keyboard',
                        'payload' => ['buttons' => [[$btn]]],
                    ]],
                    'notify' => false,
                ]);
        } catch (\Throwable $e) {
            Log::warning('[MAX] editOwnerReviewWithText failed', ['error' => $e->getMessage()]);
        }
    }

    public static function updateReviewButtons(int $userId, ?string $mid, string $reviewId, bool $isPublished): void
    {
        if (!$mid) return;
        $token = self::token();
        if (!$token) return;

        $button = $isPublished
            ? ['type' => 'callback', 'text' => '🙈 Скрыть',       'payload' => "review_pub:{$reviewId}:0"]
            : ['type' => 'callback', 'text' => '✅ Опубликовать', 'payload' => "review_pub:{$reviewId}:1"];

        try {
            Http::timeout(5)
                ->withHeaders(['Authorization' => $token])
                ->put(self::api() . '/messages?message_id=' . urlencode($mid), [
                    'attachments' => [[
                        'type'    => 'inline_keyboard',
                        'payload' => ['buttons' => [[$button]]],
                    ]],
                    'notify' => false,
                ]);
        } catch (\Throwable $e) {
            Log::warning('[MAX] updateReviewButtons failed', ['error' => $e->getMessage()]);
        }
    }

    public static function notifyMasterReview(int $userId, string $customerName, int $score, ?string $text): void
    {
        $stars = str_repeat('⭐', $score);
        $msg   = "{$stars} <b>Клиент оценил вашу работу!</b>\n\n";
        $msg  .= "👤 " . self::esc($customerName) . " поставил(а) {$score} ⭐";
        if ($text) $msg .= "\n💬 «" . self::esc($text) . "»";

        try {
            self::sendRaw($userId, $msg);
        } catch (\Throwable $e) {
            Log::warning('[MAX] notifyMasterReview failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Webhook registration ──────────────────────────────────────────────

    public static function registerWebhook(string $url): array
    {
        $token = self::token();
        if (!$token) throw new \RuntimeException('MAX bot token not configured');

        $response = Http::withHeaders(['Authorization' => $token])
            ->post(self::api() . '/subscriptions', [
                'url'          => $url,
                'update_types' => ['message_created', 'message_callback', 'bot_started'],
            ]);

        return $response->json();
    }
}
