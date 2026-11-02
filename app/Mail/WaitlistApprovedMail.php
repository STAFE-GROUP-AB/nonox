<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells someone on the waiting list that they may log in now (ADR-038). */
class WaitlistApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $applicantName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Du är godkänd – logga in i NONOX'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.waitlist-approved', with: [
            'loginUrl' => route('login'),
            'launchDate' => WaitlistConfirmationMail::launchDate(),
        ]);
    }
}
