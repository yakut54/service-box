<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $inviteUrl,
        public readonly string $shopName,
        public readonly string $email,
        public readonly bool   $requiresRegistration,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Приглашение в команду — ' . $this->shopName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-invite',
        );
    }
}
