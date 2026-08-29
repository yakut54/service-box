<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Шоперу — «у вас новый заказ». Принимает готовый массив (см.
 * MailService::orderPayload), не модель — письмо уходит через очередь и
 * обрабатывается воркером вне тенантного контекста запроса.
 */
class NewOrderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly array $order,
        public readonly string $shopName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Новый заказ #' . $this->order['short_id'] . ' — ' . $this->shopName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-order',
        );
    }
}
