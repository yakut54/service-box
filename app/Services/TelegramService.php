<?php

namespace App\Services;

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

        // TODO: Dispatch to queue for async sending
        Log::info('Telegram notification created', [
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
        $total = number_format($order->total_price / 100, 0, '.', ' ');

        $text = "New order!\n\n";
        $text .= "Order #" . substr($order->id, 0, 8) . "\n";
        $text .= "Total: {$total} RUB\n";
        $text .= "Customer: {$order->customer_name}\n";
        $text .= "Phone: {$order->customer_phone}\n";

        $inlineKeyboard = [
            [
                ['text' => 'Confirm', 'callback_data' => "order:confirm:{$order->id}"],
                ['text' => 'Cancel', 'callback_data' => "order:cancel:{$order->id}"],
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
        $date = \Carbon\Carbon::parse($booking->start_time)->format('d.m.Y');
        $time = \Carbon\Carbon::parse($booking->start_time)->format('H:i');

        $text = "New booking!\n\n";
        $text .= "Date: {$date} at {$time}\n";
        $text .= "Customer: {$booking->customer_name}\n";
        $text .= "Phone: {$booking->customer_phone}\n";

        $inlineKeyboard = [
            [
                ['text' => 'Confirm', 'callback_data' => "booking:confirm:{$booking->id}"],
                ['text' => 'Cancel', 'callback_data' => "booking:cancel:{$booking->id}"],
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
     * Generate Telegram connection code
     */
    public static function generateConnectionCode(Shop $shop): string
    {
        $code = strtoupper(substr(md5($shop->id . time()), 0, 6));

        \DB::table('telegram_codes')->insert([
            'code' => $code,
            'shop_id' => $shop->id,
            'expires_at' => now()->addMinutes(10),
            'used' => false,
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
