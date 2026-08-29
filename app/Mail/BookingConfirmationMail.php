<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Байеру — «вы записаны, вот детали». Принимает готовый массив (см.
 * MailService::bookingPayload), не модель — письмо уходит через очередь и
 * обрабатывается воркером вне тенантного контекста запроса.
 */
class BookingConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly array $booking,
        public readonly string $shopName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Вы записаны — ' . $this->shopName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-confirmation',
        );
    }
}
