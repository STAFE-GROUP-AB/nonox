<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Mail;

use App\Models\Company;
use App\Modules\Inventory\Models\PurchaseOrderVersion;
use App\Modules\Inventory\Purchasing\PurchaseOrderDocument;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A purchase order to the supplier with the PDF of the sent version
 * attached (ADR-071 §2): from the company's name, with its e-mail address to
 * reply to. Carries ids only and reads the version inside the company.
 */
class PurchaseOrderMail extends Mailable
{
    use Queueable;

    public readonly int $companyId;

    public readonly int $versionId;

    public function __construct(Company $company, PurchaseOrderVersion $version)
    {
        $this->companyId = $company->id;
        $this->versionId = $version->id;
    }

    public function envelope(): Envelope
    {
        $company = Company::query()->findOrFail($this->companyId);
        $name = $company->legal_name ?: $company->name;
        $snapshot = $this->version()->snapshot;

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $name),
            replyTo: $company->email ? [new Address($company->email, $name)] : [],
            subject: __('Inköpsorder :number från :company', ['number' => $snapshot['number'], 'company' => $name]),
        );
    }

    public function content(): Content
    {
        $company = Company::query()->findOrFail($this->companyId);

        return new Content(view: 'inventory::mail.purchase-order', with: [
            'order' => $this->version()->snapshot,
            'companyName' => $company->legal_name ?: $company->name,
            'replyTo' => $company->email,
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $snapshot = $this->version()->snapshot;

        return [Attachment::fromData(fn (): string => PurchaseOrderDocument::render(Company::query()->findOrFail($this->companyId), $snapshot), PurchaseOrderDocument::filename($snapshot))->withMime('application/pdf')];
    }

    private function version(): PurchaseOrderVersion
    {
        return CurrentCompany::run($this->companyId, fn (): PurchaseOrderVersion => PurchaseOrderVersion::query()->findOrFail($this->versionId));
    }
}
