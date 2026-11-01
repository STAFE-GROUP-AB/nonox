<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells a member that their passkeys were removed, by whom and through which company (ADR-029). */
class PasskeysResetMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $userName, public readonly string $resetBy, public readonly ?string $companyName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Dina passkeys har återställts'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.passkeys-reset', with: [
            'userName' => $this->userName,
            'resetBy' => $this->resetBy,
            'companyName' => $this->companyName,
        ]);
    }
}
