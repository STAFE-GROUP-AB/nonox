<?php

declare(strict_types=1);

namespace App\Modules\Planning\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email of a ShiftRequestNotice: the answer, the shifts concerned and a
 * link to Min sida.
 */
class ShiftRequestNoticeMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<array{day: string, time: string, area: ?string, change: string}>  $shifts
     */
    public function __construct(public readonly string $sentence, public readonly string $company, public readonly array $shifts, public readonly ?string $reason) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Om ditt pass hos :company', ['company' => $this->company]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'planning::mail.shift-request-notice');
    }
}
