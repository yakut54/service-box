<?php

namespace App\Services;

use App\Mail\BookingConfirmationMail;
use App\Mail\NewBookingMail;
use App\Mail\NewOrderMail;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderSurchargeMail;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Shop;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email-уведомления о заказах и записях — шоперу (владельцу магазина) и байеру
 * (покупателю). Тот же паттерн, что у TelegramService/MaxService: статические
 * методы notifyNewOrder/notifyNewBooking, вызываются из контроллеров.
 *
 * Mailable-классы принимают готовый массив, а не Eloquent-модель — письма уходят
 * через очередь (QUEUE_CONNECTION=database), и воркер обрабатывает их вне HTTP-
 * запроса, где TenantService ещё не выставил search_path на схему магазина.
 * Сборка payload — здесь же, внутри запроса, где тенантный контекст есть.
 */
class MailService
{
    public static function notifyNewOrder(Shop $shop, Order $order): void
    {
        $payload = self::orderPayload($order);

        if ($shop->user?->email) {
            $meta = self::meta($shop, 'order', $order->id, 'shop_owner', $shop->user->email);
            self::dispatch(
                fn () => Mail::to($shop->user->email)->queue(new NewOrderMail($payload, $shop->name, $meta)),
                $meta,
            );
        }

        if ($order->customer_email) {
            $meta = self::meta($shop, 'order', $order->id, 'buyer', $order->customer_email);
            self::dispatch(
                fn () => Mail::to($order->customer_email)->queue(new OrderConfirmationMail($payload, $shop->name, $meta)),
                $meta,
            );
        }
    }

    public static function notifyNewBooking(Shop $shop, Booking $booking): void
    {
        $payload = self::bookingPayload($booking, $shop);

        if ($shop->user?->email) {
            $meta = self::meta($shop, 'booking', $booking->id, 'shop_owner', $shop->user->email);
            self::dispatch(
                fn () => Mail::to($shop->user->email)->queue(new NewBookingMail($payload, $shop->name, $meta)),
                $meta,
            );
        }

        if ($booking->customer_email) {
            $meta = self::meta($shop, 'booking', $booking->id, 'buyer', $booking->customer_email);
            self::dispatch(
                fn () => Mail::to($booking->customer_email)->queue(new BookingConfirmationMail($payload, $shop->name, $meta)),
                $meta,
            );
        }
    }

    public static function notifySurcharge(Shop $shop, Order $order): void
    {
        if (!$order->customer_email) {
            return;
        }

        $payload = self::surchargePayload($order, $shop);
        // entity_type ограничен CHECK'ом до order/booking (mail_failures) — доплата
        // это письмо про тот же заказ, отдельного типа сущности заводить не нужно.
        $meta    = self::meta($shop, 'order', $order->id, 'buyer', $order->customer_email);

        self::dispatch(
            fn () => Mail::to($order->customer_email)->queue(new OrderSurchargeMail($payload, $shop->name, $meta)),
            $meta,
        );
    }

    /** Контекст для MailFailureRecorder — кому/о чём было письмо, если оно не дойдёт. */
    private static function meta(Shop $shop, string $entityType, string $entityId, string $recipientType, string $email): array
    {
        return [
            'shop_id' => $shop->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'recipient_type' => $recipientType,
            'recipient_email' => $email,
        ];
    }

    /**
     * Ловит только сбой ПОСТАНОВКИ в очередь (редко). Реальный сбой SMTP происходит
     * позже, внутри воркера — его ловит RecordsMailFailure::failed() на самом Mailable.
     */
    private static function dispatch(\Closure $send, array $meta): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning('Mail dispatch failed', $meta + ['error' => $e->getMessage()]);
            MailFailureRecorder::record($meta, $e->getMessage());
        }
    }

    private static function orderPayload(Order $order): array
    {
        return [
            'short_id'        => substr($order->id, 0, 8),
            'customer_name'   => $order->customer_name,
            'customer_phone'  => $order->customer_phone,
            'customer_email'  => $order->customer_email,
            'notes'           => $order->notes,
            'discount_amount' => $order->discount_amount > 0 ? self::rubles($order->discount_amount) : null,
            'total_price'     => self::rubles($order->total_price),
            'items'           => $order->items->map(fn ($item) => [
                'product_name' => $item->product_name,
                'product_type' => $item->product_type,
                'quantity'     => $item->quantity,
                'price'        => self::rubles($item->price),
            ])->all(),
        ];
    }

    private static function surchargePayload(Order $order, Shop $shop): array
    {
        $tz = $shop->timezone ?? 'Europe/Moscow';

        return [
            'short_id'         => substr($order->id, 0, 8),
            'surcharge_amount' => self::rubles($order->surcharge_amount ?? 0),
            'deadline'         => Carbon::parse($order->surcharge_deadline_at)->setTimezone($tz)->locale('ru')->translatedFormat('j F, H:i'),
            'payment_url'      => $order->surcharge_payment_url,
        ];
    }

    private static function bookingPayload(Booking $booking, Shop $shop): array
    {
        $tz    = $shop->timezone ?? 'Europe/Moscow';
        $start = Carbon::parse($booking->start_time)->setTimezone($tz)->locale('ru');
        $end   = Carbon::parse($booking->end_time)->setTimezone($tz);

        return [
            'short_id'       => substr($booking->id, 0, 8),
            'service_name'   => $booking->service?->name,
            'service_price'  => $booking->service?->price ? self::rubles($booking->service->price) : null,
            'date'           => $start->translatedFormat('j F Y'),
            'time_from'      => $start->format('H:i'),
            'time_to'        => $end->format('H:i'),
            'master_name'    => $booking->master?->name,
            'customer_name'  => $booking->customer_name,
            'customer_phone' => $booking->customer_phone,
            'customer_email' => $booking->customer_email,
            'notes'          => $booking->notes,
        ];
    }

    /** Деньги в проекте — копейки. Письма показывают целые рубли (см. widget/src/lib/utils.ts formatPrice). */
    private static function rubles(int $kopecks): string
    {
        return number_format(round($kopecks / 100), 0, '.', ' ');
    }
}
