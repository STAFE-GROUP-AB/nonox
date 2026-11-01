<?php

declare(strict_types=1);

namespace App\Support\Search\Providers;

use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Invoice;
use App\Support\Search\DatabaseSearchProvider;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kundfakturor: by invoice number, OCR, customer, amount, date and status
 * ("obetald", "förfallna", "betalda", "utkast").
 */
final class InvoicesProvider extends DatabaseSearchProvider
{
    public const string KEY = 'invoices';

    private const array COLUMNS = ['customers.name'];

    /** The list page's filter for each status. */
    private const array LIST_FILTERS = [
        SearchQuery::UNPAID => 'obetalda', SearchQuery::OVERDUE => 'forfallna', SearchQuery::PAID => 'betalda', SearchQuery::DRAFT => 'utkast',
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Kundfakturor');
    }

    public function order(): int
    {
        return 11;
    }

    public function authorize(SearchContext $context): bool
    {
        return $context->allows('viewAny', Invoice::class);
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        if ($query->hasStatus() && ! isset(self::LIST_FILTERS[$query->status])) {
            return null;
        }

        if (! $this->wants($query, amounts: true, dates: true, statuses: true)) {
            return null;
        }

        $builder = Invoice::query()
            ->where('invoices.company_id', $context->company->id)
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->select('invoices.*', 'customers.name as customer_name');

        $identifiers = [];
        $digits = $query->digits();

        if ($query->number !== null && $query->series === null) {
            $identifiers[] = ['invoices.number = ?', [$query->number]];
        }

        if ($digits !== null && strlen($digits) >= 2) {
            $identifiers[] = ['invoices.ocr = ?', [$digits]];
        }

        if ($query->impliedAmount !== null) {
            $identifiers[] = ['invoices.total_ore = ?', [$query->impliedAmount], 300];
        }

        $text = $identifiers === [] ? $query : new SearchQuery($query->raw);
        $this->whereText($builder, self::COLUMNS, $text, $this->anyOf($identifiers));
        $this->selectRank($builder, self::COLUMNS, $text, $identifiers);
        $this->whereAmount($builder, 'invoices.total_ore', $query);
        $this->whereDate($builder, 'invoices.invoice_date', $query);
        $this->whereStatus($builder, $query->status);

        [$invoices, $total] = $this->fetch($builder, 'invoices.invoice_date', $limit);

        return $this->group(
            $invoices->map(fn (Invoice $invoice): SearchResult => $this->result($invoice)->withScore(self::rank($invoice)))->all(),
            $total,
            route('invoicing.invoices.index', array_filter(['visa' => self::LIST_FILTERS[$query->status ?? ''] ?? null])),
        );
    }

    public function find(string $id, SearchContext $context): ?SearchResult
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            return null;
        }

        $invoice = Invoice::query()
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->select('invoices.*', 'customers.name as customer_name')
            ->find($id);

        return $invoice instanceof Invoice ? $this->result($invoice) : null;
    }

    public function todo(SearchContext $context): array
    {
        $overdue = Invoice::query()->where('status', InvoiceStatus::Issued->value)->whereDate('due_date', '<', CarbonImmutable::today())->count();

        if ($overdue === 0) {
            return [];
        }

        return [new SearchResult(
            type: 'todo',
            id: 'invoices-overdue',
            title: trans_choice(':count förfallen kundfaktura|:count förfallna kundfakturor', $overdue, ['count' => $overdue]),
            url: route('invoicing.invoices.index', ['visa' => 'forfallna']),
            icon: 'invoice',
            remember: false,
        )];
    }

    public function detailRoutes(): array
    {
        return ['invoicing.invoices.show' => 'invoice', 'invoicing.invoices.edit' => 'invoice'];
    }

    /** @param  Builder<Invoice>  $builder */
    private function whereStatus(Builder $builder, ?string $status): void
    {
        match ($status) {
            SearchQuery::UNPAID => $builder->where('invoices.status', InvoiceStatus::Issued->value),
            SearchQuery::OVERDUE => $builder->where('invoices.status', InvoiceStatus::Issued->value)->whereDate('invoices.due_date', '<', CarbonImmutable::today()),
            SearchQuery::PAID => $builder->whereIn('invoices.status', [InvoiceStatus::Paid->value, InvoiceStatus::Credited->value]),
            SearchQuery::DRAFT => $builder->where('invoices.status', InvoiceStatus::Draft->value),
            default => null,
        };
    }

    private function result(Invoice $invoice): SearchResult
    {
        $customer = (string) ($invoice->customer_snapshot['name'] ?? $invoice->getAttribute('customer_name'));
        $badge = match (true) {
            $invoice->isOverdue() => __('Förfallen'),
            $invoice->status === InvoiceStatus::Issued => __('Obetald'),
            $invoice->status === InvoiceStatus::Draft => __('Utkast'),
            default => $invoice->status->label(),
        };

        return new SearchResult(
            type: self::KEY,
            id: (string) $invoice->id,
            title: $invoice->title(),
            url: route('invoicing.invoices.show', $invoice),
            subtitle: self::join($customer, $invoice->invoice_date->toDateString(), $invoice->ocr ? 'OCR '.$invoice->ocr : null),
            meta: $invoice->total_ore->format(),
            badge: $badge,
            icon: 'invoice',
        );
    }
}
