<?php

namespace App\Services;

use App\Contracts\PushTransport;
use App\Models\CustomerPushToken;
use App\Models\Order;
use App\Models\Shop;
use App\Support\Money;
use App\Support\PushMessage;
use App\Support\PushSendResult;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound as FcmTokenNotFound;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * Настоящий push через Firebase Cloud Messaging (HTTP v1 API, kreait/firebase-php).
 * Не настроено (нет FIREBASE_CREDENTIALS_PATH) — молча выключен, как MAIL_MAILER=log
 * до настройки SMTP (см. MailFailureController::mailConfigured — тот же паттерн,
 * «опциональный канал», не ошибка).
 *
 * Реализует App\Contracts\PushTransport::send() — низкоуровневую отправку на один
 * токен. Событийные методы (notifySurcharge и т.д.) собирают PushMessage и гоняют
 * его через транспорт по всем токенам покупателя.
 */
class FirebaseService implements PushTransport
{
    private static ?Messaging $messaging = null;
    private static bool $attempted = false;

    /** Живая проверка «канал настроен» — для будущего бейджа в админке. */
    public static function configured(): bool
    {
        return self::messaging() !== null;
    }

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

    public function send(string $token, PushMessage $message): PushSendResult
    {
        $messaging = self::messaging();
        if (!$messaging) {
            return PushSendResult::TransientError; // канал не настроен — не ошибка данных, слать нечем
        }

        $android = ['priority' => $message->priority === 'normal' ? 'normal' : 'high'];
        if ($message->collapseKey) {
            $android['collapse_key'] = $message->collapseKey;
        }

        $data = $message->data;
        if ($message->channelId) {
            $data['channel_id'] = $message->channelId;
        }

        $cloud = CloudMessage::withTargetToken($token)
            ->withNotification(Notification::create($message->title, $message->body))
            ->withData($data)
            ->withAndroidConfig(AndroidConfig::fromArray($android));

        try {
            $messaging->send($cloud);
            return PushSendResult::Ok;
        } catch (FcmTokenNotFound) {
            return PushSendResult::InvalidToken;
        } catch (\Throwable $e) {
            Log::warning('Firebase send failed', ['error' => $e->getMessage()]);
            return PushSendResult::TransientError;
        }
    }

    /** Доплата за перевзвешенный заказ (см. OrderReweighService::finalizeOrder). */
    public static function notifySurcharge(Order $order, Shop $shop): void
    {
        $customer = $order->customer;
        if (!$customer) {
            return;
        }

        $tokens = $customer->pushTokens()->pluck('token');
        if ($tokens->isEmpty()) {
            return;
        }

        $amount = Money::rubles($order->surcharge_amount);

        $message = new PushMessage(
            title: 'Требуется доплата',
            body: "Фактический вес больше заявленного — доплатите {$amount} ₽",
            data: ['type' => 'order_surcharge', 'order_id' => (string) $order->id],
            channelId: 'orders',
        );

        $transport = app(PushTransport::class);

        foreach ($tokens as $token) {
            $result = $transport->send($token, $message);

            if ($result === PushSendResult::InvalidToken) {
                CustomerPushToken::where('token', $token)->delete();
                PushFailureRecorder::record([
                    'shop_id'     => $shop->id,
                    'customer_id' => $customer->id,
                    'entity_type' => 'order_surcharge',
                    'entity_id'   => $order->id,
                ], 'FCM token no longer registered', tokenInvalidated: true);
            }
        }
    }
}
