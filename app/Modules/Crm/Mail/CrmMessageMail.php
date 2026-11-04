<?php

declare(strict_types=1);

namespace App\Modules\Crm\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * A one-to-one e-mail from a template on an account or person (ADR-048).
 *
 * Sent from the service's own address (MAIL_FROM_ADDRESS, the domain the
 * service can send for: SPF and DKIM pass) with the company's name as the
 * display name, and the sender's own address as Reply-To, so the answer
 * reaches them and can be logged with a BCC. NONOX never puts a company's
 * domain in From: that would be spoofing a domain the service cannot sign.
 */
class CrmMessageMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly string $mailSubject,
        public readonly string $text,
        public readonly string $replyToEmail,
        public readonly string $replyToName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->companyName),
            replyTo: [new Address($this->replyToEmail, $this->replyToName)],
            subject: $this->mailSubject,
        );
    }

    public function headers(): Headers
    {
        // No open or click tracking by the sending service either (owner's decision, ADR-043).
        return new Headers(text: ['X-Mailgun-Track' => 'no', 'X-Mailgun-Track-Opens' => 'no', 'X-Mailgun-Track-Clicks' => 'no']);
    }

    public function content(): Content
    {
        return new Content(view: 'crm::mail.message', text: 'crm::mail.message-text');
    }
}
