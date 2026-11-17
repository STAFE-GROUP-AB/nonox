<?php

declare(strict_types=1);

namespace App\Modules\Planning\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The e-mail of a ScheduleMessageNotice: that there is a new message about
 * the schedule, with a link to Min sida. Never the message text.
 */
class ScheduleMessageMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $company, public readonly bool $requiresAck) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Nytt meddelande om schemat hos :company', ['company' => $this->company]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'planning::mail.schedule-message');
    }
}
