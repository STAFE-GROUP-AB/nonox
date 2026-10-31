<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A reminder that someone is still checked in after many hours: to the
 * person (when the register has their email) or to the managers. Nobody is
 * checked out automatically.
 */
class ForgottenCheckOutMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<array{name: string, location: string, since: string}>  $people
     */
    public function __construct(public readonly array $people, public readonly string $company, public readonly bool $toPerson) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->toPerson
            ? __('Har du glömt att checka ut?')
            : __('Glömda utcheckningar i personalliggaren'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'staff-ledger::mail.forgotten-check-out', with: ['url' => route('staff-ledger.overview')]);
    }
}
