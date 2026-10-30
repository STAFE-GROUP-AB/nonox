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
        return new Envelope(subject: __(':name: :what', ['name' => $this->employeeName, 'what' => $this->what]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'payroll::mail.absence-requested', with: ['url' => route('payroll.requests')]);
    }
}
