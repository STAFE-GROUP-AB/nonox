<?php

declare(strict_types=1);

namespace App\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Confirms a place on the waiting list of the open beta (ADR-038). Only the
 * name is carried, not the answers: the mail must not repeat what someone
 * else may have typed in with this address.
 */
class WaitlistConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $applicantName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Du står i kö till NONOX'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.waitlist-confirmation', with: [
            'launchDate' => self::launchDate(),
            'privacyUrl' => route('legal.privacy'),
        ]);
    }

    /** The launch date of version 1.0 in Swedish, such as "1 februari 2027". */
    public static function launchDate(): string
    {
        return CarbonImmutable::parse((string) config('nonox.launch_date'))->settings(['locale' => 'sv'])->translatedFormat('j F Y');
    }
}
