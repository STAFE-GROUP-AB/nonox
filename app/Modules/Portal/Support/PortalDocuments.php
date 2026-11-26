<?php

declare(strict_types=1);

namespace App\Modules\Portal\Support;

use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\SalesDocumentStatus;
use App\Domain\Pricing\PriceFloor;
use App\Domain\Pricing\PriceFloorGuard;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\SalesDocument;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
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
    private function sentDocuments(): Builder
    {
        return SalesDocument::query()
            ->where('customer_id', $this->context->customer()->id)
            ->whereNotNull('sent_at');
    }

    /**
     * One page of the sent quotes and orders the customer may see, newest
     * first. Only the documents on the page are checked against the price
     * floor (ADR-082 §9), so the work does not grow with the history. A
     * simple paginator is used on purpose: a total would count the documents
     * that are held back. A page whose documents are all held is empty.
     *
     * @return Paginator<int, SalesDocument>
     */
    public function salesDocumentsPage(int $perPage): Paginator
    {
        $page = $this->sentDocuments()->orderByDesc('document_date')->orderByDesc('number')->simplePaginate($perPage);
        $held = $this->heldAmong($page->getCollection());

        return $page->setCollection($page->getCollection()->reject(fn (SalesDocument $document): bool => in_array($document->id, $held, true))->values());
    }

    /** The number of open quotes the customer may see. */
    public function openQuoteCount(): int
    {
        $open = $this->sentDocuments()->where('kind', SalesDocument::QUOTE)->where('status', SalesDocumentStatus::Open->value);

        if (! $this->floorRequiresApproval()) {
            return $open->count();
        }

        $documents = $open->get();

        return $documents->count() - count($this->heldAmong($documents));
    }

    /**
     * Sent quotes and orders changed after they were sent, whose manual prices
     * under the company's floor are not approved in the rows they have now
     * (ADR-082 §9): they stay sent, but the customer sees neither the document
     * nor its PDF until they are approved again. Only the given documents are
     * checked, and nothing is read for a company without a floor that requires
     * approval; otherwise their rows, costs and approvals are loaded once.
     *
     * @param  Collection<int, SalesDocument>  $documents
     * @return list<string>
     */
    private function heldAmong(Collection $documents): array
    {
        if ($documents->isEmpty() || ! $this->floorRequiresApproval()) {
            return [];
        }

        $company = Company::query()->find(CurrentCompany::id());

        return $company instanceof Company ? PriceFloorGuard::heldIds($company, new EloquentCollection($documents->all())) : [];
    }

    private function floorRequiresApproval(): bool
    {
        $company = Company::query()->find(CurrentCompany::id());

        return $company instanceof Company && PriceFloor::of($company)->requiresApproval();
    }

    public function salesDocument(string $id): SalesDocument
    {
        abort_unless(Str::isUuid($id), 404);

        $document = $this->sentDocuments()->whereKey($id)->firstOrFail();

        abort_if($this->heldAmong(new Collection([$document])) !== [], 404);

        return $document;
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
