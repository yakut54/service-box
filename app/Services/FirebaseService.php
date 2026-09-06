<?php

namespace App\Services;

use App\Contracts\PushTransport;
use App\Models\Customer;
use App\Models\CustomerPushToken;
use App\Models\Order;
use App\Models\Shop;
use App\Support\Money;
use App\Support\PushMessage;
use App\Support\PushSendResult;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\AuthenticationError as FcmAuthError;
use Kreait\Firebase\Exception\Messaging\InvalidMessage as FcmInvalidMessage;
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

        $cloud = CloudMessage::withTarget('token', $token)
            ->withNotification(Notification::create($message->title, $message->body))
            ->withData($data)
            ->withAndroidConfig(AndroidConfig::fromArray($android));

        try {
            $messaging->send($cloud);
            return PushSendResult::Ok;
        } catch (FcmTokenNotFound) {
            return PushSendResult::InvalidToken; // 404 UNREGISTERED
        } catch (FcmInvalidMessage $e) {
            // 400. kreait шлёт InvalidMessage и на битый токен («registration
            // token is not a valid FCM registration token»), и на кривой payload.
            // Различаем по тексту: токен — удаляем, payload — это наш баг.
            if (stripos($e->getMessage(), 'registration token') !== false) {
                return PushSendResult::InvalidToken;
            }
            Log::error('Firebase send: bad message payload', ['error' => $e->getMessage()]);
            return PushSendResult::TransientError;
        } catch (FcmAuthError $e) {
            Log::error('Firebase auth error — проверь FIREBASE_CREDENTIALS_PATH', ['error' => $e->getMessage()]);
            return PushSendResult::TransientError;
        } catch (\Throwable $e) {
            Log::warning('Firebase send failed', ['error' => $e->getMessage()]);
            return PushSendResult::TransientError;
        }
    }

    /**
     * Отправить один PushMessage на ВСЕ токены покупателя. Мёртвый токен
     * (InvalidToken) удаляется из customer_push_tokens и пишется в push_failures.
     * Вызывать только в тенантном контексте магазина ($shop). Ничего не бросает.
     *
     * @param string      $entityType для push_failures ('order_surcharge', 'order_status', ...)
     * @param string|null $entityId   id связанной сущности (для отладки)
     * @return bool доставлено в шлюз хотя бы на один токен (не гарантия показа)
     */
    public static function sendToCustomer(
        Shop $shop,
        Customer $customer,
        PushMessage $message,
        string $entityType,
        ?string $entityId = null,
    ): bool {
        // Аварийный выключатель на магазин (мастер-админка → «Push покупателям»).
        // Единственная точка гейта — сюда сходятся все пуши покупателю. Мессенджеры
        // при этом продолжают работать (фолбэк в Notifier).
        if (!$shop->customer_push_enabled) {
            return false;
        }

        $tokens = $customer->pushTokens()->pluck('token');
        if ($tokens->isEmpty()) {
            return false;
        }

        $transport = app(PushTransport::class);
        $delivered = false;

        foreach ($tokens as $token) {
            $result = $transport->send($token, $message);

            if ($result === PushSendResult::Ok) {
                $delivered = true;
            } elseif ($result === PushSendResult::InvalidToken) {
                CustomerPushToken::where('token', $token)->delete();
                PushFailureRecorder::record([
                    'shop_id'     => $shop->id,
                    'customer_id' => $customer->id,
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                ], 'FCM token no longer registered', tokenInvalidated: true);
            }
        }

        return $delivered;
    }

    /** Доплата за перевзвешенный заказ (см. OrderReweighService::finalizeOrder). */
    public static function notifySurcharge(Order $order, Shop $shop): void
    {
        $customer = $order->customer;
        if (!$customer) {
            return;
        }

        $amount = Money::rubles($order->surcharge_amount);

        self::sendToCustomer($shop, $customer, new PushMessage(
            title: 'Требуется доплата',
            body: "Фактический вес больше заявленного — доплатите {$amount} ₽",
            data: ['type' => 'order_surcharge', 'order_id' => (string) $order->id],
            channelId: 'orders',
        ), 'order_surcharge', (string) $order->id);
    }
}
