<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Exception\Messaging\NotFound as FcmTokenNotFound;

/**
 * Настоящий push через Firebase Cloud Messaging (HTTP v1 API, kreait/firebase-php).
 * Не настроено (нет FIREBASE_CREDENTIALS_PATH) — молча выключен, как MAIL_MAILER=log
 * до настройки SMTP (см. MailFailureController::mailConfigured — тот же паттерн,
 * «опциональный канал», не ошибка).
 */
class FirebaseService
{
    private static ?Messaging $messaging = null;
    private static bool $attempted = false;

    private static function messaging(): ?Messaging
    {
        if (self::$attempted) {
            return self::$messaging;
        }
        self::$attempted = true;

        $path = config('services.firebase.credentials_path');
        if (!$path || !is_file($path)) {
            return null;
        }

        try {
            self::$messaging = (new Factory())->withServiceAccount($path)->createMessaging();
        } catch (\Throwable $e) {
            Log::error('Firebase init failed', ['error' => $e->getMessage()]);
            self::$messaging = null;
        }

        return self::$messaging;
    }

    /** Доплата за перевзвешенный заказ (см. OrderReweighService::finalizeOrder). */
    public static function notifySurcharge(Order $order): void
    {
        $messaging = self::messaging();
        $token = $order->customer?->fcm_token;

        if (!$messaging || !$token) {
            return;
        }

        $amount = number_format(($order->surcharge_amount ?? 0) / 100, 0, ',', ' ');

        $message = CloudMessage::withTargetToken($token)
            ->withNotification(Notification::create(
                'Требуется доплата',
                "Фактический вес больше заявленного — доплатите {$amount} ₽",
            ))
            ->withData([
                'type' => 'order_surcharge',
                'order_id' => $order->id,
            ]);

        try {
            $messaging->send($message);
        } catch (FcmTokenNotFound) {
            // Токен устарел (переустановка, новый телефон) — тихо игнорируем,
            // остальные каналы (Telegram/MAX/email/баннер) всё равно достучатся.
        } catch (\Throwable $e) {
            Log::warning('Firebase send failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
