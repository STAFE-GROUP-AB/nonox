<?php

declare(strict_types=1);

namespace App\Support\Search\Providers;

use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\SupplierInvoice;
use App\Support\Search\DatabaseSearchProvider;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Leverantörsfakturor: by our number, the supplier's invoice number, OCR,
 * supplier, description, amount, date and status ("obetald", "att attestera").
 */
final class SupplierInvoicesProvider extends DatabaseSearchProvider
{
    public const string KEY = 'supplier-invoices';

    private const array COLUMNS = ['suppliers.name', 'supplier_invoices.description', 'supplier_invoices.invoice_number'];

    private const array LIST_FILTERS = [
        SearchQuery::UNPAID => 'att-betala', SearchQuery::OVERDUE => 'forfallna', SearchQuery::PAID => 'betalda',
        SearchQuery::DRAFT => 'utkast', SearchQuery::TO_APPROVE => 'attest',
    ];

    private const array OPEN = [SupplierInvoiceStatus::Registered->value, SupplierInvoiceStatus::Approved->value];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Leverantörsfakturor');
    }

    public function order(): int
    {
        return 12;
    }

    public function authorize(SearchContext $context): bool
    {
        return $context->allows('viewAny', SupplierInvoice::class);
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        if ($query->hasStatus() && ! isset(self::LIST_FILTERS[$query->status])) {
            return null;
        }

        if (! $this->wants($query, amounts: true, dates: true, statuses: true)) {
            return null;
        }

        $builder = SupplierInvoice::query()
            ->where('supplier_invoices.company_id', $context->company->id)
            ->leftJoin('suppliers', 'suppliers.id', '=', 'supplier_invoices.supplier_id')
            ->select('supplier_invoices.*', 'suppliers.name as supplier_name');

        $identifiers = [];
        $digits = $query->digits();

        if ($query->number !== null && $query->series === null) {
            $identifiers[] = ['supplier_invoices.number = ?', [$query->number]];
        }

        if ($digits !== null && strlen($digits) >= 2) {
            $identifiers[] = ['supplier_invoices.ocr = ?', [$digits]];
            $identifiers[] = ['supplier_invoices.invoice_number = ?', [$digits]];
        }

        if ($query->impliedAmount !== null) {
            $identifiers[] = ['supplier_invoices.total_ore = ?', [$query->impliedAmount], 300];
        }

        $text = $identifiers === [] ? $query : new SearchQuery($query->raw);
        $this->whereText($builder, self::COLUMNS, $text, $this->anyOf($identifiers));
        $this->selectRank($builder, self::COLUMNS, $text, $identifiers);
        $this->whereAmount($builder, 'supplier_invoices.total_ore', $query);
        $this->whereDate($builder, 'supplier_invoices.invoice_date', $query);
        $this->whereStatus($builder, $query->status);

        [$invoices, $total] = $this->fetch($builder, 'supplier_invoices.invoice_date', $limit);

        return $this->group(
            $invoices->map(fn (SupplierInvoice $invoice): SearchResult => $this->result($invoice)->withScore(self::rank($invoice)))->all(),
            $total,
            route('payables.invoices.index', array_filter(['visa' => self::LIST_FILTERS[$query->status ?? ''] ?? null])),
        );
    }

    public function find(string $id, SearchContext $context): ?SearchResult
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            return null;
        }

        $invoice = SupplierInvoice::query()
            ->leftJoin('suppliers', 'suppliers.id', '=', 'supplier_invoices.supplier_id')
            ->select('supplier_invoices.*', 'suppliers.name as supplier_name')
            ->find($id);

        return $invoice instanceof SupplierInvoice ? $this->result($invoice) : null;
    }

    public function todo(SearchContext $context): array
    {
        $todo = [];

        if ($context->can('supplier-invoices:approve')) {
            $toApprove = SupplierInvoice::query()->where('status', SupplierInvoiceStatus::Registered->value)->count();

            if ($toApprove > 0) {
                $todo[] = new SearchResult(
                    type: 'todo',
                    id: 'supplier-invoices-approve',
                    title: trans_choice(':count leverantörsfaktura att attestera|:count leverantörsfakturor att attestera', $toApprove, ['count' => $toApprove]),
                    url: route('payables.invoices.index', ['visa' => 'attest']),
                    icon: 'supplier-invoice',
                    remember: false,
                );
            }
        }

        if ($context->can('payments:create')) {
            $toPay = SupplierInvoice::query()->where('status', SupplierInvoiceStatus::Approved->value)->count();

            if ($toPay > 0) {
                $todo[] = new SearchResult(
                    type: 'todo',
                    id: 'supplier-invoices-pay',
                    title: trans_choice(':count leverantörsfaktura att betala|:count leverantörsfakturor att betala', $toPay, ['count' => $toPay]),
                    url: route('payables.invoices.index', ['visa' => 'att-betala']),
                    icon: 'supplier-invoice',
                    remember: false,
                );
            }
        }

        return $todo;
    }

    public function detailRoutes(): array
    {
        return ['payables.invoices.show' => 'supplierInvoice', 'payables.invoices.edit' => 'supplierInvoice'];
    }

    /** @param  Builder<SupplierInvoice>  $builder */
    private function whereStatus(Builder $builder, ?string $status): void
    {
        match ($status) {
            SearchQuery::UNPAID => $builder->whereIn('supplier_invoices.status', self::OPEN),
            SearchQuery::OVERDUE => $builder->whereIn('supplier_invoices.status', self::OPEN)->whereDate('supplier_invoices.due_date', '<', CarbonImmutable::today()),
            SearchQuery::PAID => $builder->where('supplier_invoices.status', SupplierInvoiceStatus::Paid->value),
            SearchQuery::DRAFT => $builder->where('supplier_invoices.status', SupplierInvoiceStatus::Draft->value),
            SearchQuery::TO_APPROVE => $builder->where('supplier_invoices.status', SupplierInvoiceStatus::Registered->value),
            default => null,
        };
    }

    private function result(SupplierInvoice $invoice): SearchResult
    {
        $supplier = (string) ($invoice->supplier_snapshot['name'] ?? $invoice->getAttribute('supplier_name'));

        return new SearchResult(
            type: self::KEY,
            id: (string) $invoice->id,
            title: $invoice->title(),
            url: route('payables.invoices.show', $invoice),
            subtitle: self::join($supplier, self::when((string) $invoice->invoice_number !== '', __('Fakturanr :number', ['number' => $invoice->invoice_number])), $invoice->invoice_date->toDateString()),
            meta: $invoice->total_ore->format(),
            badge: $invoice->isOverdue() ? __('Förfallen') : $invoice->status->label(),
            icon: 'supplier-invoice',
        );
    }
}
