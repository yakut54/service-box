<?php

namespace App\Services;

use App\Jobs\SendTelegramMessage as SendTelegramMessageJob;
use App\Models\Shop;
use App\Models\TelegramMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    /**
     * Send message to Telegram
     */
    public static function sendMessage(
        Shop $shop,
        string $text,
        ?array $inlineKeyboard = null,
        ?string $type = 'system_notification',
        ?string $entityType = null,
        ?string $entityId = null
    ): ?TelegramMessage {
        if (!$shop->telegram_chat_id || !$shop->telegram_bot_connected) {
            return null;
        }

        $botToken = config('services.telegram.bot_token');
        if (!$botToken) {
            Log::error('Telegram bot token not configured');
            return null;
        }

        $telegramMessage = TelegramMessage::create([
            'shop_id' => $shop->id,
            'telegram_chat_id' => $shop->telegram_chat_id,
            'type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'message_text' => $text,
            'inline_keyboard' => $inlineKeyboard,
            'status' => 'pending',
        ]);

        // Dispatch job to send message via Bot API
        SendTelegramMessageJob::dispatch($telegramMessage->id);

        Log::info('Telegram notification queued', [
            'message_id' => $telegramMessage->id,
            'shop_id' => $shop->id,
            'type' => $type,
        ]);

        return $telegramMessage;
    }

    /**
     * Notify about new order
     */
    public static function notifyNewOrder(Shop $shop, $order): void
    {
        $total = number_format($order->total_price, 0, '.', ' ');

        $text  = "🛒 <b>Новый заказ!</b>\n\n";
        $text .= "🔖 №" . substr($order->id, 0, 8) . "\n";
        $text .= "💰 <b>{$total} ₽</b>\n";
        $text .= "\n";
        $text .= "Клиент: " . self::esc($order->customer_name) . "\n";
        $text .= "Телефон: " . self::esc($order->customer_phone) . "\n";

        $deliveryLabels = [
            'pickup'  => 'Самовывоз',
            'courier' => 'Курьер',
            'postal'  => 'Почта / СДЭК',
        ];
        $method = $order->delivery_method ?? null;
        if ($method) {
            $label = $deliveryLabels[$method] ?? self::esc($method);
            $icon  = $method === 'pickup' ? '🏪' : '📦';
            $deliveryLine = "{$icon} {$label}";
            if ($order->delivery_price > 0) {
                $deliveryLine .= ' — ' . number_format($order->delivery_price, 0, '.', ' ') . ' ₽';
            }
            $text .= "\n{$deliveryLine}\n";
        }

        $addr = $order->shipping_address;
        if ($addr && in_array($method, ['courier', 'postal'])) {
            $street = implode(', ', array_filter([$addr['city'] ?? null, $addr['street'] ?? null]));
            $house  = implode(', ', array_filter([
                !empty($addr['building'])  ? 'д. '  . $addr['building']  : null,
                !empty($addr['apartment']) ? 'кв. ' . $addr['apartment'] : null,
            ]));
            if ($street) $text .= self::esc($street) . "\n";
            if ($house)  $text .= self::esc($house) . "\n";
            if (!empty($addr['postal_code'])) {
                $text .= self::esc($addr['postal_code']) . "\n";
            }
        }

        if (!empty($order->notes)) {
            $text .= "\n💬 " . self::esc($order->notes) . "\n";
        }

        $inlineKeyboard = [
            [
                ['text' => '✅ Подтвердить', 'callback_data' => "order:confirm:{$order->id}"],
                ['text' => '❌ Отменить',    'callback_data' => "order:cancel:{$order->id}"],
            ],
        ];

        self::sendMessage(
            shop: $shop,
            text: $text,
            inlineKeyboard: $inlineKeyboard,
            type: 'order_notification',
            entityType: 'Order',
            entityId: $order->id
        );
    }

    /**
     * Notify about new booking
     */
    public static function notifyNewBooking(Shop $shop, $booking): void
    {
        $tz      = $shop->timezone ?? 'Europe/Moscow';
        $dt      = \Carbon\Carbon::parse($booking->start_time)->setTimezone($tz)->locale('ru');
        $date    = $dt->translatedFormat('j M, D');
        $time    = $dt->format('H:i');
        $service = $booking->service?->name ?? '—';
        $master  = $booking->master?->name;

        $price = $booking->service?->price
            ? '💰 ' . number_format($booking->service->price, 0, '.', ' ') . " ₽\n"
            : '';

        $text  = "📅 <b>Новая запись!</b>\n\n";
        $text .= "🕐 <b>{$date} в {$time}</b>\n";
        $text .= "📋 " . self::esc($service) . "\n";
        if ($master) {
            $text .= "👤 " . self::esc($master) . "\n";
        }
        $text .= $price;
        $text .= "\n";
        $text .= "Клиент: " . self::esc($booking->customer_name) . "\n";
        $text .= "Телефон: " . self::esc($booking->customer_phone) . "\n";
        if (!empty($booking->notes)) {
            $text .= "\n💬 " . self::esc($booking->notes) . "\n";
        }

        $inlineKeyboard = [
            [
                ['text' => '✅ Подтвердить', 'callback_data' => "booking:confirm:{$booking->id}"],
                ['text' => '❌ Отменить',    'callback_data' => "booking:cancel:{$booking->id}"],
            ],
        ];

        self::sendMessage(
            shop: $shop,
            text: $text,
            inlineKeyboard: $inlineKeyboard,
            type: 'booking_notification',
            entityType: 'Booking',
            entityId: $booking->id
        );
    }

    /**
     * Generate a deep-link token so a customer can link their Telegram.
     * Returns the full t.me URL to put in the widget.
     */
    public static function generateCustomerLinkToken(Shop $shop, string $customerPhone): string
    {
        $token = bin2hex(random_bytes(16)); // 32-char hex

        \DB::table("{$shop->schema_name}.telegram_link_tokens")->insert([
            'token'          => $token,
            'customer_phone' => $customerPhone,
            'expires_at'     => now()->addHours(24),
            'used'           => false,
            'created_at'     => now(),
        ]);

        $botUsername = config('services.telegram.bot_username', 'sb_widget_bot');

        return "https://t.me/{$botUsername}?start=c{$token}";
    }

    /**
     * Link customer's Telegram chat_id to their phone in the shop schema.
     */
    public static function linkCustomer(Shop $shop, string $customerPhone, int $chatId): void
    {
        \DB::table("{$shop->schema_name}.customers")
            ->where('phone', $customerPhone)
            ->update(['telegram_chat_id' => $chatId]);
    }

    /**
     * Notify customer when owner confirms or cancels their booking.
     */
    public static function notifyBookingStatusToCustomer(Shop $shop, $booking, string $status): void
    {
        $chatId = \DB::table("{$shop->schema_name}.customers")
            ->where('phone', $booking->customer_phone)
            ->value('telegram_chat_id');

        Log::info('[TG] notifyBookingStatusToCustomer', [
            'booking' => $booking->id,
            'status'  => $status,
            'phone'   => $booking->customer_phone,
            'chat_id' => $chatId,
        ]);

        if (!$chatId) {
            Log::warning('[TG] notifyBookingStatusToCustomer: no telegram_chat_id, skipping', ['phone' => $booking->customer_phone]);
            return;
        }

        $botToken = config('services.telegram.bot_token');
        if (!$botToken) {
            return;
        }

        $tz   = $shop->timezone ?? 'Europe/Moscow';
        $dt   = \Carbon\Carbon::parse($booking->start_time)->setTimezone($tz);
        $date = $dt->translatedFormat('j M');
        $time = $dt->format('H:i');
        $service = $booking->service?->name ?? '—';
        $master  = $booking->master?->name;

        $keyboard = null;

        if ($status === 'confirmed') {
            $text  = "✅ <b>Ваша запись подтверждена!</b>\n\n";
            $text .= "🕐 <b>{$date} в {$time}</b>\n";
            $text .= "📋 " . self::esc($service) . "\n";
            if ($master) {
                $text .= "👤 " . self::esc($master) . "\n";
            }
            $text .= "\n<b>" . self::esc($shop->name) . "</b>";
            $keyboard = ['inline_keyboard' => [[
                ['text' => '❌ Отменить запись', 'callback_data' => "client:cancel:{$booking->id}"],
            ]]];
        } elseif ($status === 'completed') {
            $text  = "✅ <b>Визит завершён. Спасибо!</b>\n\n";
            $text .= "🕐 {$date} в {$time}\n";
            $text .= "📋 " . self::esc($service) . "\n\n";
            $text .= "Ждём вас снова 😊";
        } elseif ($status === 'cancelled') {
            $text  = "❌ <b>Ваша запись отменена</b>\n\n";
            $text .= "🕐 {$date} в {$time}\n";
            $text .= "📋 " . self::esc($service) . "\n\n";
            $text .= "Хотите записаться на другое время?";

            if ($shop->domain) {
                $bookUrl = str_starts_with($shop->domain, 'http') ? $shop->domain : "https://{$shop->domain}";
            } else {
                $bookUrl = url("/book/{$shop->api_key}");
            }
            $keyboard = ['inline_keyboard' => [[['text' => '📅 Записаться снова', 'url' => $bookUrl]]]];
        } else {
            return;
        }

        // Remove cancel button from confirmation message before sending new status
        if (in_array($status, ['completed', 'cancelled'])) {
            $cached = \Illuminate\Support\Facades\Cache::get("tg_cust_confirm_mid:{$booking->id}");
            if ($cached) {
                \Illuminate\Support\Facades\Http::timeout(5)
                    ->post("https://api.telegram.org/bot{$botToken}/editMessageReplyMarkup", [
                        'chat_id'      => $cached['chat_id'],
                        'message_id'   => $cached['message_id'],
                        'reply_markup' => json_encode(['inline_keyboard' => []]),
                    ]);
            }
        }

        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            $payload['reply_markup'] = json_encode($keyboard);
        }

        $response = \Illuminate\Support\Facades\Http::timeout(5)
            ->post("https://api.telegram.org/bot{$botToken}/sendMessage", $payload);

        // Store message_id of confirmation so we can remove its button later
        if ($status === 'confirmed') {
            $messageId = $response->json('result.message_id');
            if ($messageId) {
                \Illuminate\Support\Facades\Cache::put(
                    "tg_cust_confirm_mid:{$booking->id}",
                    ['chat_id' => $chatId, 'message_id' => $messageId],
                    now()->addDays(7)
                );
            }
        }
    }

    /**
     * Send booking confirmation to the customer's Telegram.
     */
    public static function notifyBookingConfirmedToCustomer(Shop $shop, $booking): void
    {
        $chatId = \DB::table("{$shop->schema_name}.customers")
            ->where('phone', $booking->customer_phone)
            ->value('telegram_chat_id');

        if (!$chatId) {
            return;
        }

        $botToken = config('services.telegram.bot_token');
        if (!$botToken) {
            return;
        }

        $dt      = \Carbon\Carbon::parse($booking->start_time)->setTimezone($shop->timezone ?? 'Europe/Moscow');
        $date    = $dt->translatedFormat('j M, D');
        $time    = $dt->format('H:i');
        $service = $booking->service?->name ?? '—';
        $master  = $booking->master?->name;

        $text  = "✅ <b>Запись подтверждена!</b>\n\n";
        $text .= "🕐 <b>{$date} в {$time}</b>\n";
        $text .= "📋 " . self::esc($service) . "\n";
        if ($master) {
            $text .= "👤 " . self::esc($master) . "\n";
        }
        $text .= "\n<b>" . self::esc($shop->name) . "</b>";

        $keyboard = [
            [['text' => '❌ Отменить запись', 'callback_data' => "client:cancel:{$booking->id}"]],
        ];

        \Illuminate\Support\Facades\Http::timeout(5)
            ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            ]);
    }

    /**
     * Send booking reminder to customer (24h or 2h before).
     */
    public static function notifyBookingReminder(Shop $shop, object $booking, string $type): void
    {
        $chatId = \DB::table("{$shop->schema_name}.customers")
            ->where('phone', $booking->customer_phone)
            ->value('telegram_chat_id');

        if (!$chatId) {
            return;
        }

        $botToken = config('services.telegram.bot_token');
        if (!$botToken) {
            return;
        }

        $tz      = $shop->timezone ?? 'Europe/Moscow';
        $dt      = \Carbon\Carbon::parse($booking->start_time)->setTimezone($tz)->locale('ru');
        $date    = $dt->translatedFormat('j M, D');
        $time    = $dt->format('H:i');
        $service = $booking->service_name ?? '—';
        $master  = $booking->master_name  ?? null;

        $label = $type === '24h' ? 'Завтра' : 'Через 2 часа';

        $text  = "⏰ <b>Напоминание о записи</b>\n\n";
        $text .= "🕐 <b>{$label}, {$date} в {$time}</b>\n";
        $text .= "📋 " . self::esc($service) . "\n";
        if ($master) {
            $text .= "👤 " . self::esc($master) . "\n";
        }
        $text .= "\n<b>" . self::esc($shop->name) . "</b>";

        $keyboard = [
            [['text' => '❌ Отменить запись', 'callback_data' => "client:cancel:{$booking->id}"]],
        ];

        \Illuminate\Support\Facades\Http::timeout(5)
            ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            ]);
    }

    /**
     * Ask customer to rate their completed booking.
     */
    public static function notifyRatingRequest(Shop $shop, object $booking): void
    {
        $chatId = \DB::table("{$shop->schema_name}.customers")
            ->where('phone', $booking->customer_phone)
            ->value('telegram_chat_id');

        Log::info('[TG] notifyRatingRequest', [
            'booking' => $booking->id,
            'phone'   => $booking->customer_phone,
            'chat_id' => $chatId,
        ]);

        if (!$chatId) {
            Log::warning('[TG] notifyRatingRequest: no telegram_chat_id, skipping', ['phone' => $booking->customer_phone]);
            return;
        }

        $botToken = config('services.telegram.bot_token');
        if (!$botToken) {
            return;
        }

        $service = $booking->service_name ?? $booking->service?->name ?? '—';

        $text  = "⭐ <b>Как прошёл визит?</b>\n\n";
        $text .= "📋 " . self::esc($service) . "\n";
        $text .= "\nОцените, пожалуйста, услугу:";

        $keyboard = [[
            ['text' => '1 ⭐', 'callback_data' => "rate:{$booking->id}:1"],
            ['text' => '2 ⭐', 'callback_data' => "rate:{$booking->id}:2"],
            ['text' => '3 ⭐', 'callback_data' => "rate:{$booking->id}:3"],
            ['text' => '4 ⭐', 'callback_data' => "rate:{$booking->id}:4"],
            ['text' => '5 ⭐', 'callback_data' => "rate:{$booking->id}:5"],
        ]];

        \Illuminate\Support\Facades\Http::timeout(5)
            ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            ]);
    }

    /**
     * Generate Telegram connection code
     */
    public static function generateConnectionCode(Shop $shop): string
    {
        $code = strtoupper(substr(md5($shop->id . time()), 0, 6));

        \DB::table('telegram_codes')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'code' => $code,
            'shop_id' => $shop->id,
            'expires_at' => now()->addMinutes(10),
            'used' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $code;
    }

    /**
     * Verify connection code and return associated shop
     */
    public static function verifyConnectionCode(string $code): ?Shop
    {
        $telegramCode = \DB::table('telegram_codes')
            ->where('code', $code)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$telegramCode) {
            return null;
        }

        $shop = Shop::find($telegramCode->shop_id);

        \DB::table('telegram_codes')
            ->where('code', $code)
            ->update(['used' => true]);

        return $shop;
    }

    /**
     * Connect Telegram to shop
     */
    public static function connectTelegram(Shop $shop, int $chatId): void
    {
        $shop->update([
            'telegram_chat_id' => $chatId,
            'telegram_bot_connected' => true,
        ]);

        Log::info('Telegram connected', [
            'shop_id' => $shop->id,
            'chat_id' => $chatId,
        ]);
    }

    public static function notifyOwnerCompletionRequest(Shop $shop, object $booking): void
    {
        $tz      = $shop->timezone ?? 'Europe/Moscow';
        $start   = \Carbon\Carbon::parse($booking->start_time)->setTimezone($tz)->format('H:i');
        $end     = \Carbon\Carbon::parse($booking->end_time)->setTimezone($tz)->format('H:i');
        $service = $booking->service_name ?? '—';
        $name    = $booking->customer_name;

        $text = "📋 " . self::esc($service) . " — {$start}–{$end} (" . self::esc($name) . ")\nКак прошло?";

        self::sendMessage(
            shop: $shop,
            text: $text,
            inlineKeyboard: [[
                ['text' => '✅ Завершить', 'callback_data' => "booking:complete:{$booking->id}"],
                ['text' => '👻 Неявка',   'callback_data' => "booking:noshow:{$booking->id}"],
            ]],
            type: 'system_notification',
            entityType: 'Booking',
            entityId: $booking->id
        );
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

        self::sendMessage(
            shop: $shop,
            text: "Запись {$date} {$time} (" . self::esc($name) . ") — {$label}",
            type: 'system_notification',
            entityType: 'Booking',
            entityId: $booking->id
        );
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // ── Master messenger methods ──────────────────────────────────────────

    public static function generateMasterLinkToken(\App\Models\ShopStaff $staff): string
    {
        $token = bin2hex(random_bytes(16)); // 32-char hex
        $staff->update([
            'messenger_link_token'            => $token,
            'messenger_link_token_expires_at' => now()->addHours(24),
        ]);
        $botUsername = config('services.telegram.bot_username', 'sb_widget_bot');
        return "https://t.me/{$botUsername}?start=m{$token}";
    }

    public static function notifyMasterNewBooking(int $chatId, $booking, Shop $shop): void
    {
        $botToken = config('services.telegram.bot_token');
        if (!$botToken) return;

        $tz      = $shop->timezone ?? 'Europe/Moscow';
        $dt      = \Carbon\Carbon::parse($booking->start_time)->setTimezone($tz)->locale('ru');
        $date    = $dt->translatedFormat('j M, D');
        $time    = $dt->format('H:i');
        $service = $booking->service?->name ?? '—';

        $text  = "📅 <b>Новая запись!</b>\n\n";
        $text .= "🕐 <b>{$date} в {$time}</b>\n";
        $text .= "📋 " . self::esc($service) . "\n\n";
        $text .= "👤 " . self::esc($booking->customer_name);
        if (empty($shop->hide_customer_phone) && !empty($booking->customer_phone)) {
            $text .= "\n📞 " . self::esc($booking->customer_phone);
        }
        if (!empty($booking->notes)) $text .= "\n\n💬 " . self::esc($booking->notes);

        \Illuminate\Support\Facades\Http::timeout(5)
            ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id'    => $chatId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]);
    }

    public static function notifyMasterBookingStatus(int $chatId, $booking, string $status, Shop $shop): void
    {
        $botToken = config('services.telegram.bot_token');
        if (!$botToken) return;

        $label = match($status) {
            'cancelled' => '❌ Запись отменена',
            default     => null,
        };
        if (!$label) return;

        $tz   = $shop->timezone ?? 'Europe/Moscow';
        $dt   = \Carbon\Carbon::parse($booking->start_time)->setTimezone($tz);
        $date = $dt->format('d.m H:i');

        \Illuminate\Support\Facades\Http::timeout(5)
            ->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id'    => $chatId,
                'text'       => "{$label}\n🕐 {$date} — " . self::esc($booking->customer_name),
                'parse_mode' => 'HTML',
            ]);
    }

    public static function notifyMasterReminder(int $chatId, object $booking, string $type, Shop $shop): void
    {
        $botToken = config('services.telegram.bot_token');
        if (!$botToken) return;

        $tz      = $shop->timezone ?? 'Europe/Moscow';
        $dt      = \Carbon\Carbon::parse($booking->start_time)->setTimezone($tz)->locale('ru');
        $date    = $dt->translatedFormat('j M, D');
        $time    = $dt->format('H:i');
        $service = $booking->service_name ?? '—';
        $label   = $type === '24h' ? 'Завтра' : 'Через 2 часа';

        $text  = "⏰ <b>Напоминание</b>\n\n";
        $text .= "🕐 <b>{$label}, {$date} в {$time}</b>\n";
        $text .= "📋 " . self::esc($service) . "\n";
        $text .= "👤 " . self::esc($booking->customer_name);

        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];

        if ($type === '2h') {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => [[
                ['text' => '✅ Пришёл',    'callback_data' => "master_booking:arrived:{$booking->id}"],
                ['text' => '👻 Не пришёл', 'callback_data' => "master_booking:noshow:{$booking->id}"],
            ]]]);
        }

        \Illuminate\Support\Facades\Http::timeout(5)
            ->post("https://api.telegram.org/bot{$botToken}/sendMessage", $payload);
    }

    public static function removeKeyboardByEntity(string $entityType, string $entityId): void
    {
        $msg = TelegramMessage::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->whereNotNull('telegram_message_id')
            ->latest()
            ->first();

        if (!$msg || !$msg->telegram_message_id || !$msg->telegram_chat_id) {
            return;
        }

        $botToken = config('services.telegram.bot_token');
        if (!$botToken) return;

        try {
            Http::timeout(5)->post("https://api.telegram.org/bot{$botToken}/editMessageReplyMarkup", [
                'chat_id'      => $msg->telegram_chat_id,
                'message_id'   => $msg->telegram_message_id,
                'reply_markup' => ['inline_keyboard' => []],
            ]);
        } catch (\Throwable) {}
    }
}
