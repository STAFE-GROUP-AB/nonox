<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The daily summary of a company's inbox: counts per tab and what arrived
 * in the last day, without the contents.
 */
class InboxSummaryMail extends Mailable
{
    use Queueable;

    /**
     * @param  array{review: int, ready: int, booked: int, received: int}  $counts
     */
    public function __construct(public readonly string $companyName, public readonly array $counts) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Inkorgen i :company i dag', ['company' => $this->companyName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'inbox::mail.summary', with: ['url' => route('inbox.index')]);
    }
}
