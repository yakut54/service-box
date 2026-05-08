<?php

namespace App\Services;

use App\Jobs\SendTelegramMessage as SendTelegramMessageJob;
use App\Models\Shop;
use App\Models\TelegramMessage;
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
        $text .= "Клиент: {$order->customer_name}\n";
        $text .= "Телефон: {$order->customer_phone}\n";

        $addr = $order->shipping_address;
        if ($addr && $order->items()->where('product_type', 'physical')->exists()) {
            $street = implode(', ', array_filter([$addr['city'] ?? null, $addr['street'] ?? null]));
            $house  = implode(', ', array_filter([
                !empty($addr['building'])  ? 'д. '  . $addr['building']  : null,
                !empty($addr['apartment']) ? 'кв. ' . $addr['apartment'] : null,
            ]));
            $text .= "\n📦 Доставка:\n{$street}\n{$house}";
            if (!empty($addr['postal_code'])) {
                $text .= "\n" . $addr['postal_code'];
            }
            if (!empty($order->notes)) {
                $text .= "\n\n💬 {$order->notes}";
            }
            $text .= "\n";
        } elseif (!empty($order->notes)) {
            $text .= "\n💬 {$order->notes}\n";
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
        $text .= "📋 {$service}\n";
        if ($master) {
            $text .= "👤 {$master}\n";
        }
        $text .= $price;
        $text .= "\n";
        $text .= "Клиент: {$booking->customer_name}\n";
        $text .= "Телефон: {$booking->customer_phone}\n";
        if (!empty($booking->notes)) {
            $text .= "\n💬 {$booking->notes}\n";
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

        if (!$chatId) {
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
            $text .= "📋 {$service}\n";
            if ($master) {
                $text .= "👤 {$master}\n";
            }
            $text .= "\n<b>{$shop->name}</b>";
        } else {
            $text  = "❌ <b>Ваша запись отменена</b>\n\n";
            $text .= "🕐 {$date} в {$time}\n";
            $text .= "📋 {$service}\n\n";
            $text .= "Хотите записаться на другое время?";

            if ($shop->domain) {
                $bookUrl = str_starts_with($shop->domain, 'http') ? $shop->domain : "https://{$shop->domain}";
            } else {
                $bookUrl = url("/book/{$shop->api_key}");
            }
            $keyboard = ['inline_keyboard' => [[['text' => '📅 Записаться снова', 'url' => $bookUrl]]]];
        }

        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            $payload['reply_markup'] = json_encode($keyboard);
        }

        \Illuminate\Support\Facades\Http::timeout(5)
            ->post("https://api.telegram.org/bot{$botToken}/sendMessage", $payload);
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
        $text .= "📋 {$service}\n";
        if ($master) {
            $text .= "👤 {$master}\n";
        }
        $text .= "\n<b>{$shop->name}</b>";

        $keyboard = [
            [['text' => '❌ Отменить запись', 'callback_data' => "client_cancel:{$booking->id}"]],
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
        $text .= "📋 {$service}\n";
        if ($master) {
            $text .= "👤 {$master}\n";
        }
        $text .= "\n<b>{$shop->name}</b>";

        $keyboard = [
            [['text' => '❌ Отменить запись', 'callback_data' => "client_cancel:{$booking->id}"]],
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
}
