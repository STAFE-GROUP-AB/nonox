<?php

declare(strict_types=1);

namespace App\Modules\Crm\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The company's mailings were paused automatically (ADR-048): why, and where to resume. */
class MailingsPausedMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $companyName, public readonly string $reason) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Utskicken i :company är pausade', ['company' => $this->companyName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'crm::mail.mailings-paused', with: ['url' => route('crm.mailings')]);
    }
}
