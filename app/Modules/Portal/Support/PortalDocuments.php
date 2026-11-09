<?php

declare(strict_types=1);

namespace App\Modules\Portal\Support;

use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\SalesDocumentStatus;
use App\Models\Invoice;
use App\Models\SalesDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * What a contact may see (ADR-075 §4), always for the context's customer
 * inside the context's company (the company filter comes from CurrentCompany,
 * set by ResolvePortal):
 *
 * - invoices and credit invoices: never a draft; never one written off
 *   (decision O5: hidden until the owner decides); issued within the
 *   company's history setting (default 24 months), and every open one
 *   whatever its age;
 * - quotes and orders that were sent (sent_at set).
 */
final class PortalDocuments
{
    public function __construct(private readonly PortalContext $context) {}

    /** @return Builder<Invoice> */
    public function invoices(): Builder
    {
        $since = CarbonImmutable::today()->subMonthsNoOverflow($this->context->settings()->history_months)->toDateString();

        return Invoice::query()
            ->where('customer_id', $this->context->customer()->id)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::WrittenOff->value])
            ->whereNotNull('number')
            ->where(fn (Builder $query) => $query->where('invoice_date', '>=', $since)->orWhere('status', InvoiceStatus::Issued->value));
    }

    /** One visible invoice by its number, or 404. */
    public function invoice(int $number): Invoice
    {
        return $this->invoices()->where('number', $number)->firstOrFail();
    }

    /** @return Builder<SalesDocument> */
    public function salesDocuments(): Builder
    {
        return SalesDocument::query()
            ->where('customer_id', $this->context->customer()->id)
            ->whereNotNull('sent_at');
    }

    public function salesDocument(string $id): SalesDocument
    {
        abort_unless(Str::isUuid($id), 404);

        return $this->salesDocuments()->whereKey($id)->firstOrFail();
    }

    /** @return Builder<SalesDocument> */
    public function openQuotes(): Builder
    {
        return $this->salesDocuments()->where('kind', SalesDocument::QUOTE)->where('status', SalesDocumentStatus::Open->value);
    }

    /**
     * The portal's status of an invoice: Obetald, Förfallen, Delvis betald,
     * Betald, Krediterad, or for a credit invoice with money to pay back,
     * Att återbetala.
     */
    public static function status(Invoice $invoice, ?CarbonImmutable $today = null): string
    {
        if ($invoice->isCredit()) {
            return $invoice->statusLabel();
        }

        if ($invoice->status !== InvoiceStatus::Issued) {
            return $invoice->status->label();
        }

        if ($invoice->isOverdue($today)) {
            return __('Förfallen');
        }

        return $invoice->paid_ore->ore !== 0 ? __('Delvis betald') : __('Obetald');
    }
}
