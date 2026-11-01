<?php

declare(strict_types=1);

namespace App\Modules\Connect\Mail;

use App\Modules\Connect\Webhooks\WebhookEvents;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the company's owner (and the creator) that a webhook now sends the
 * company's events to an address outside NONOX (ADR-032), so one made by
 * someone else does not go unseen. Never contains the secret.
 */
class WebhookCreatedMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<string>  $events
     */
    public function __construct(
        public readonly string $companyName,
        public readonly string $creatorName,
        public readonly string $url,
        public readonly array $events,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('En webhook har lagts till för :company', ['company' => $this->companyName]));
    }

    public function content(): Content
    {
        $labels = WebhookEvents::labels();

        return new Content(markdown: 'connect::mail.webhook-created', with: [
            'companyName' => $this->companyName,
            'creatorName' => $this->creatorName,
            'url' => $this->url,
            'events' => array_map(fn (string $event): string => $labels[$event] ?? $event, $this->events),
        ]);
    }
}
