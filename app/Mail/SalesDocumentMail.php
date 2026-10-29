<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Invoicing\SalesDocumentPdf;
use App\Models\Company;
use App\Models\SalesDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A quote or an order confirmation sent to the customer with the PDF attached.
 */
class SalesDocumentMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public SalesDocument $document, public Company $company) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(':title från :company', ['title' => $this->document->isQuote() ? $this->document->title() : __('Orderbekräftelse :number', ['number' => $this->document->number]), 'company' => $this->company->legal_name ?: $this->company->name]),
            replyTo: $this->company->email ? [$this->company->email] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.sales-document');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => SalesDocumentPdf::render($this->document), SalesDocumentPdf::filename($this->document))->withMime('application/pdf'),
        ];
    }
}
