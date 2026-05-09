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
        }
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

        if (!$callbackId || !$userId) return;

        $parts = explode(':', $payload, 3);
        if (count($parts) !== 3) {
            MaxService::answerCallback($callbackId, 'Неизвестная команда');
            return;
        }

        [$entityType, $action, $entityId] = $parts;

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
}
