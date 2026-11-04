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
 * One recipient's copy of a mailing (ADR-048), already rendered.
 *
 * From is the service's own address with the company's name (the domain
 * the service signs with SPF and DKIM; never the company's domain), and
 * Reply-To the company's e-mail. List-Unsubscribe with
 * List-Unsubscribe-Post gives one-click unsubscribe in the mail client
 * (RFC 8058, required by the large mailbox providers for bulk mail).
 * Mailgun's own open and click tracking is switched off per message, so
 * no pixel is ever added; X-Mailgun-Variables carries the recipient id for
 * the delivery and bounce webhook.
 */
class MailingMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $fromName,
        public readonly ?string $replyToEmail,
        public readonly string $mailSubject,
        public readonly string $htmlBody,
        public readonly string $plain,
        public readonly ?string $unsubscribeUrl,
        public readonly ?int $recipientId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->fromName),
            replyTo: $this->replyToEmail ? [new Address($this->replyToEmail, $this->fromName)] : [],
            subject: $this->mailSubject,
        );
    }

    public function headers(): Headers
    {
        $headers = ['X-Mailgun-Track' => 'no', 'X-Mailgun-Track-Opens' => 'no', 'X-Mailgun-Track-Clicks' => 'no'];

        if ($this->unsubscribeUrl !== null) {
            $headers['List-Unsubscribe'] = "<{$this->unsubscribeUrl}>";
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        if ($this->recipientId !== null) {
            $headers['X-Mailgun-Variables'] = (string) json_encode(['nonox_recipient' => $this->recipientId]);
        }

        return new Headers(text: $headers);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody, text: 'crm::mail.plain');
    }
}
