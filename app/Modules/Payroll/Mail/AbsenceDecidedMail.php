<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the employee: the application is approved or refused.
 */
class AbsenceDecidedMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $firstName, public readonly string $what, public readonly string $dates, public readonly bool $approved, public readonly ?string $reason, public readonly string $company) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->approved
            ? __('Godkänd: :what :dates', ['what' => $this->what, 'dates' => $this->dates])
            : __('Inte godkänd: :what :dates', ['what' => $this->what, 'dates' => $this->dates]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'payroll::mail.absence-decided', with: ['url' => route('payroll.portal.home')]);
    }
}
