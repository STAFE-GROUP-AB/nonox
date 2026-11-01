<?php

declare(strict_types=1);

namespace App\Support\Search\Providers;

use App\Domain\Invoicing\SalesDocumentStatus;
use App\Models\SalesDocument;
use App\Support\Search\DatabaseSearchProvider;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;

/**
 * Offerter or order (one provider for each): by number, customer, amount,
 * date, and "öppna".
 */
final class SalesDocumentsProvider extends DatabaseSearchProvider
{
    private const array COLUMNS = ['customers.name'];

    public function __construct(private readonly string $kind) {}

    public static function quotes(): self
    {
        return new self(SalesDocument::QUOTE);
    }

    public static function orders(): self
    {
        return new self(SalesDocument::ORDER);
    }

    public function key(): string
    {
        return $this->kind === SalesDocument::QUOTE ? 'quotes' : 'orders';
    }

    public function label(): string
    {
        return $this->kind === SalesDocument::QUOTE ? __('Offerter') : __('Order');
    }

    public function order(): int
    {
        return $this->kind === SalesDocument::QUOTE ? 13 : 14;
    }

    public function authorize(SearchContext $context): bool
    {
        return $context->allows('viewAny', SalesDocument::class);
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        if ($query->hasStatus() && $query->status !== SearchQuery::UNPAID) {
            return null;
        }

        if (! $this->wants($query, amounts: true, dates: true, statuses: true)) {
            return null;
        }

        $builder = SalesDocument::query()
            ->where('sales_documents.company_id', $context->company->id)
            ->where('sales_documents.kind', $this->kind)
            ->leftJoin('customers', 'customers.id', '=', 'sales_documents.customer_id')
            ->select('sales_documents.*', 'customers.name as customer_name');

        $identifiers = [];

        if ($query->number !== null && $query->series === null) {
            $identifiers[] = ['sales_documents.number = ?', [$query->number]];
        }

        if ($query->impliedAmount !== null) {
            $identifiers[] = ['sales_documents.total_ore = ?', [$query->impliedAmount], 300];
        }

        $text = $identifiers === [] ? $query : new SearchQuery($query->raw);
        $this->whereText($builder, self::COLUMNS, $text, $this->anyOf($identifiers));
        $this->selectRank($builder, self::COLUMNS, $text, $identifiers);
        $this->whereAmount($builder, 'sales_documents.total_ore', $query);
        $this->whereDate($builder, 'sales_documents.document_date', $query);

        if ($query->status === SearchQuery::UNPAID) {
            $builder->where('sales_documents.status', SalesDocumentStatus::Open->value);
        }

        [$documents, $total] = $this->fetch($builder, 'sales_documents.document_date', $limit);

        return $this->group(
            $documents->map(fn (SalesDocument $document): SearchResult => $this->result($document)->withScore(self::rank($document)))->all(),
            $total,
            route($this->route(), array_filter(['visa' => $query->status === null ? 'alla' : null])),
        );
    }

    public function find(string $id, SearchContext $context): ?SearchResult
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            return null;
        }

        $document = SalesDocument::query()
            ->where('sales_documents.kind', $this->kind)
            ->leftJoin('customers', 'customers.id', '=', 'sales_documents.customer_id')
            ->select('sales_documents.*', 'customers.name as customer_name')
            ->find($id);

        return $document instanceof SalesDocument ? $this->result($document) : null;
    }

    public function detailRoutes(): array
    {
        return ["{$this->route()}.show" => 'document'];
    }

    private function route(): string
    {
        return $this->kind === SalesDocument::QUOTE ? 'invoicing.quotes' : 'invoicing.orders';
    }

    private function result(SalesDocument $document): SearchResult
    {
        return new SearchResult(
            type: $this->key(),
            id: (string) $document->id,
            title: $document->title(),
            url: route("{$this->route()}.show", $document),
            subtitle: self::join((string) $document->getAttribute('customer_name'), $document->document_date->toDateString()),
            meta: $document->total_ore->format(),
            badge: $document->status->label(),
            icon: $this->kind === SalesDocument::QUOTE ? 'quote' : 'order',
        );
    }
}
