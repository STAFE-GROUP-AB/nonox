<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Something in the inbox needs a person. Says only how many, never what:
 * the contents are seen inside NONOX.
 */
class InboxActionNeededMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $companyName, public readonly int $waiting) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Inkorgen i :company behöver granskas', ['company' => $this->companyName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'inbox::mail.action-needed', with: ['url' => route('inbox.index')]);
    }
}
