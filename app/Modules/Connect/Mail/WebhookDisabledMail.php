<?php

declare(strict_types=1);

namespace App\Modules\Connect\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the company's owner (and the webhook's creator) that NONOX turned a
 * webhook off because the receiver did not answer for about a day
 * (ADR-032). Contains the address and the last status code, never the
 * secret or the events' contents.
 */
class WebhookDisabledMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly string $url,
        public readonly ?string $description,
        public readonly string $eventType,
        public readonly ?int $lastStatusCode,
        public readonly ?string $lastError,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('En webhook för :company har stängts av', ['company' => $this->companyName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'connect::mail.webhook-disabled', with: [
            'companyName' => $this->companyName,
            'url' => $this->url,
            'description' => $this->description,
            'eventType' => $this->eventType,
            'lastAnswer' => $this->lastStatusCode !== null
                ? __('mottagaren svarade med HTTP-status :status', ['status' => $this->lastStatusCode])
                : __('mottagaren svarade inte (:reason)', ['reason' => (string) $this->lastError]),
        ]);
    }
}
