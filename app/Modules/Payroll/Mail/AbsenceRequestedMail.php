<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To payroll staff: an employee has reported absence or applied for vacation on Min sida.
 */
class AbsenceRequestedMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $employeeName, public readonly string $what, public readonly string $dates, public readonly string $company) {}

    public function envelope(): Envelope
    {
        // Neutral on purpose: the kind of absence can be health data and is shown only inside NONOX.
        return new Envelope(subject: __('Ny ansökan från :name', ['name' => $this->employeeName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'payroll::mail.absence-requested', with: ['url' => route('payroll.requests')]);
    }
}
