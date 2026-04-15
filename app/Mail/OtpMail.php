<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $otp, public string $name)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your LAB by GenZIITian verification code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            with: [
                'otp' => $this->otp,
                'name' => $this->name,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
