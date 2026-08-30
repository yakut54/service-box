<?php

namespace App\Mail;

use App\Mail\Concerns\RecordsMailFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Байеру — «спасибо за заказ, вот детали». Принимает готовый массив (см.
 * MailService::orderPayload), не модель — письмо уходит через очередь и
 * обрабатывается воркером вне тенантного контекста запроса.
 */
class OrderConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, RecordsMailFailure;

    public function __construct(
        public readonly array $order,
        public readonly string $shopName,
        array $failureMeta,
    ) {
        $this->failureMeta = $failureMeta;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ваш заказ #' . $this->order['short_id'] . ' принят — ' . $this->shopName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-confirmation',
        );
    }
}
