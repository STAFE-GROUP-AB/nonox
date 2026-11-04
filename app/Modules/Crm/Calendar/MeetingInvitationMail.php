<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A meeting invitation to a CRM person (ADR-049): a short text and the
 * meeting as an iCalendar file (METHOD:REQUEST), which mail programs show
 * with buttons to accept. Replies go to the user who planned the meeting.
 */
class MeetingInvitationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public CalendarEntry $entry,
        public string $ics,
        public string $companyName,
        public string $senderName,
        public string $senderEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Inbjudan: :subject', ['subject' => $this->entry->summary]),
            replyTo: [new Address($this->senderEmail, $this->senderName)],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'crm::mail.meeting-invitation');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->ics, 'inbjudan.ics')->withMime('text/calendar; charset=UTF-8; method=REQUEST'),
        ];
    }
}
