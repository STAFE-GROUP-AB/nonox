<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The daily summary of the waiting list for the HQ admins (ADR-038):
 * counts and company names only. The answers are read in /hq.
 */
class WaitlistDigestMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $companies  the company names of the entries of the last day
     */
    public function __construct(public readonly array $companies, public readonly int $pending) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Kön till NONOX: :count nya senaste dygnet', ['count' => count($this->companies)]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.waitlist-digest', with: ['url' => route('hq.waitlist')]);
    }
}
