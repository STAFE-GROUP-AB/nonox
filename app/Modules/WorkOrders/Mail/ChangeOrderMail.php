<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Mail;

use App\Models\Company;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Support\ChangeOrderPdf;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The customer's copy of a decided ÄTA as a PDF (AO-R10: a durable copy
 * either way, L5). Carries ids only and reads the ÄTA inside the company.
 */
class ChangeOrderMail extends Mailable
{
    use Queueable;

    public readonly int $companyId;

    public readonly int $changeOrderId;

    public function __construct(Company $company, ChangeOrder $changeOrder)
    {
        $this->companyId = $company->id;
        $this->changeOrderId = $changeOrder->id;
    }

    public function envelope(): Envelope
    {
        $company = Company::query()->findOrFail($this->companyId);
        $name = $company->legal_name ?: $company->name;

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $name),
            replyTo: $company->email ? [$company->email] : [],
            subject: __(':ata för arbetsorder :number', ['ata' => $this->changeOrder()->label(), 'number' => $this->changeOrder()->workOrder?->number]),
        );
    }

    public function content(): Content
    {
        $company = Company::query()->findOrFail($this->companyId);

        return new Content(view: 'work-orders::mail.change-order', with: [
            'changeOrder' => $this->changeOrder(),
            'companyName' => $company->legal_name ?: $company->name,
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $changeOrder = $this->changeOrder();

        return [Attachment::fromData(fn (): string => CurrentCompany::run($this->companyId, fn (): string => ChangeOrderPdf::render($changeOrder)), ChangeOrderPdf::filename($changeOrder))->withMime('application/pdf')];
    }

    private function changeOrder(): ChangeOrder
    {
        return CurrentCompany::run($this->companyId, fn (): ChangeOrder => ChangeOrder::query()->with('workOrder')->findOrFail($this->changeOrderId));
    }
}
