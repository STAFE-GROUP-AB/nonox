<?php

declare(strict_types=1);

namespace App\Modules\Planning\Mail;

use App\Modules\Planning\Notifications\ScheduleNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email of a ScheduleNotice: the employee's shifts in the published
 * period, or the shift that was cancelled.
 */
class ScheduleNoticeMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<array{day: string, time: string, area: ?string, change: string}>  $shifts
     */
    public function __construct(public readonly string $kind, public readonly string $company, public readonly string $period, public readonly array $shifts) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->kind === ScheduleNotice::CANCELLED
            ? __('Ett pass är inställt hos :company', ['company' => $this->company])
            : __('Ditt schema för :period hos :company', ['period' => $this->period, 'company' => $this->company]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'planning::mail.schedule-notice');
    }
}
