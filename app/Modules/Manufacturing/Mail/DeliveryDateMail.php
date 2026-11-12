<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mail;

use App\Models\Company;
use App\Models\SalesDocument;
use App\Modules\Manufacturing\Models\CustomerDateChange;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A confirmed delivery date to the customer (ADR-071 §13.5): the order
 * number and the date, from the company's name with its e-mail address to
 * reply to. It never says why a date moved. Carries ids only.
 */
class DeliveryDateMail extends Mailable
{
    use Queueable;

    public readonly int $companyId;

    public readonly int $changeId;

    public function __construct(Company $company, CustomerDateChange $change)
    {
        $this->companyId = $company->id;
        $this->changeId = $change->id;
        // The customer is Swedish unless told otherwise: customers have no language of their own yet.
        $this->locale('sv');
    }

    public function envelope(): Envelope
    {
        $company = Company::query()->findOrFail($this->companyId);
        $name = $company->legal_name ?: $company->name;

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $name),
            replyTo: $company->email ? [new Address($company->email, $name)] : [],
            subject: __('Leveransdatum för order :number', ['number' => $this->data()['number']]),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'manufacturing::mail.delivery-date', with: $this->data());
    }

    /** @return array{number: int, date: string, previous: ?string, companyName: string, replyTo: ?string} */
    public function data(): array
    {
        $company = Company::query()->findOrFail($this->companyId);

        return CurrentCompany::run($this->companyId, function () use ($company): array {
            $change = CustomerDateChange::query()->findOrFail($this->changeId);
            $document = SalesDocument::query()->findOrFail($change->sales_document_id);

            return [
                'number' => (int) $document->number,
                'date' => $change->new_on->settings(['locale' => 'sv'])->translatedFormat('l j F Y'),
                'previous' => $change->old_on?->settings(['locale' => 'sv'])->translatedFormat('j F Y'),
                'companyName' => $company->legal_name ?: $company->name,
                'replyTo' => $company->email,
            ];
        });
    }
}
