<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Invoicing\IssuedInvoicePdf;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A friendly reminder for an overdue invoice, with the invoice attached.
 */
class ReminderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Invoice $invoice, public InvoiceReminder $reminder, public Company $company) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Påminnelse: :title från :company', ['title' => $this->invoice->title(), 'company' => $this->company->legal_name ?: $this->company->name]),
            replyTo: $this->company->email ? [$this->company->email] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.reminder');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        // An imported invoice has no NONOX document to attach (ADR-076); the mail quotes the old number, the OCR and the amount.
        if ($this->invoice->isImported()) {
            return [];
        }

        return [
            Attachment::fromData(fn (): string => IssuedInvoicePdf::bytes($this->invoice), InvoicePdf::filename($this->invoice))->withMime('application/pdf'),
        ];
    }
}
