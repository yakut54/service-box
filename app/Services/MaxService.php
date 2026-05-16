<?php

namespace App\Services;

use App\Models\Shop;
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

        $total = number_format($order->total_price, 0, '.', ' ');

        $deliveryLabels = [
            'pickup'  => 'Самовывоз',
            'courier' => 'Курьер',
            'postal'  => 'Почта / СДЭК',
        ];

        $text  = "🛒 <b>Новый заказ!</b>\n\n";
        $text .= "🔖 №" . substr($order->id, 0, 8) . "\n";
        $text .= "💰 <b>{$total} ₽</b>\n\n";
        $text .= "Клиент: {$order->customer_name}\n";
        $text .= "Телефон: {$order->customer_phone}";

        $method = $order->delivery_method ?? null;
        if ($method) {
            $label = $deliveryLabels[$method] ?? $method;
            $icon  = $method === 'pickup' ? '🏪' : '📦';
            $deliveryLine = "\n{$icon} {$label}";
            if ($order->delivery_price > 0) {
                $deliveryLine .= ' — ' . number_format($order->delivery_price, 0, '.', ' ') . ' ₽';
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
            if ($street) $text .= "\n{$street}";
            if ($house)  $text .= "\n{$house}";
            if (!empty($addr['postal_code'])) $text .= "\n" . $addr['postal_code'];
        }

        if (!empty($order->notes)) {
            $text .= "\n\n💬 {$order->notes}";
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

        $text = "📋 {$service} — {$start}–{$end} ({$name})\nКак прошло?";

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

        self::sendMessage($shop, "Запись {$date} {$time} ({$name}) — {$label}");
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
        $text .= "📋 {$service}\n";
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
        $text .= "📋 {$service}\n";
        if ($master) $text .= "👤 {$master}\n";

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

        $text = "{$label}\n\n📋 {$service}\n🕐 {$date} в {$time}";

        $buttons = null;
        if ($status === 'confirmed') {
            $buttons = [[
                ['type' => 'callback', 'text' => '❌ Отменить запись', 'payload' => "client:cancel:{$bookingId}"],
            ]];
        }

        try {
            $mid = self::sendRaw($userId, $text, $buttons);
            if ($mid && $status === 'confirmed') {
                Cache::put("max_cust_cancel_mid:{$bookingId}", ['user_id' => $userId, 'mid' => $mid], now()->addDays(7));
            }
        } catch (\Throwable) {}
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
