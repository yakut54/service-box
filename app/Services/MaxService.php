<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
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

    public static function sendMessage(Shop $shop, string $text, ?array $buttons = null): void
    {
        if (!$shop->max_bot_connected || !$shop->max_chat_id) {
            return;
        }

        $token = self::token();
        if (!$token) {
            Log::error('MAX bot token not configured');
            return;
        }

        $body = ['text' => $text, 'format' => 'html'];

        if ($buttons) {
            $body['attachments'] = [[
                'type'    => 'inline_keyboard',
                'payload' => ['buttons' => $buttons],
            ]];
        }

        Http::timeout(5)
            ->withHeaders(['Authorization' => $token])
            ->post(self::api() . '/messages?user_id=' . $shop->max_chat_id, $body)
            ->throw();
    }

    public static function sendRaw(int $chatId, string $text, ?array $buttons = null): void
    {
        $token = self::token();
        if (!$token) return;

        $body = ['text' => $text, 'format' => 'html'];

        if ($buttons) {
            $body['attachments'] = [[
                'type'    => 'inline_keyboard',
                'payload' => ['buttons' => $buttons],
            ]];
        }

        Http::timeout(5)
            ->withHeaders(['Authorization' => $token])
            ->post(self::api() . '/messages?user_id=' . $chatId, $body);
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
        if (!$mid) return;

        $token = self::token();
        if (!$token) return;

        try {
            Http::timeout(5)
                ->withHeaders(['Authorization' => $token])
                ->patch(self::api() . '/messages?message_id=' . urlencode($mid) . '&user_id=' . $userId, [
                    'attachments' => [],
                ]);
        } catch (\Throwable) {}
    }

    // ── Owner notifications ───────────────────────────────────────────────

    public static function notifyNewOrder(Shop $shop, $order): void
    {
        if (!$shop->max_bot_connected) return;

        $total = number_format($order->total_price, 0, '.', ' ');

        $text  = "🛒 <b>Новый заказ!</b>\n\n";
        $text .= "🔖 №" . substr($order->id, 0, 8) . "\n";
        $text .= "💰 <b>{$total} ₽</b>\n\n";
        $text .= "Клиент: {$order->customer_name}\n";
        $text .= "Телефон: {$order->customer_phone}";

        if (!empty($order->notes)) {
            $text .= "\n\n💬 {$order->notes}";
        }

        $buttons = [[
            ['type' => 'callback', 'text' => '✅ Подтвердить', 'payload' => "order:confirm:{$order->id}"],
            ['type' => 'callback', 'text' => '❌ Отменить',    'payload' => "order:cancel:{$order->id}"],
        ]];

        try {
            self::sendMessage($shop, $text, $buttons);
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
            ? '💰 ' . number_format($booking->service->price, 0, '.', ' ') . " ₽\n"
            : '';

        $text  = "📅 <b>Новая запись!</b>\n\n";
        $text .= "🕐 <b>{$date} в {$time}</b>\n";
        $text .= "📋 {$service}\n";
        if ($master) $text .= "👤 {$master}\n";
        $text .= $price;
        $text .= "\nКлиент: {$booking->customer_name}\n";
        $text .= "Телефон: {$booking->customer_phone}";
        if (!empty($booking->notes)) $text .= "\n\n💬 {$booking->notes}";

        $buttons = [[
            ['type' => 'callback', 'text' => '✅ Подтвердить', 'payload' => "booking:confirm:{$booking->id}"],
            ['type' => 'callback', 'text' => '❌ Отменить',    'payload' => "booking:cancel:{$booking->id}"],
        ]];

        try {
            self::sendMessage($shop, $text, $buttons);
        } catch (\Throwable $e) {
            Log::warning('MAX notify booking failed', ['error' => $e->getMessage()]);
        }
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
