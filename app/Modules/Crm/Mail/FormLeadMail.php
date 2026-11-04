<?php

declare(strict_types=1);

namespace App\Modules\Crm\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A web form brought a new lead (ADR-048). Names only the form, never who
 * wrote: the details are seen inside NONOX.
 */
class FormLeadMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly string $formName,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Ny intresseanmälan i :company', ['company' => $this->companyName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'crm::mail.form-lead');
    }
}
