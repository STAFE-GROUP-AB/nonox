<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells the user a passkey was added to their account, so one added by someone else does not go unseen (ADR-029). */
class PasskeyAddedMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $userName, public readonly string $passkeyName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('En passkey har lagts till på ditt konto'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.passkey-added', with: [
            'userName' => $this->userName,
            'passkeyName' => $this->passkeyName,
        ]);
    }
}
