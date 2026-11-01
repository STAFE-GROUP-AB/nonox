<?php

declare(strict_types=1);

namespace App\Support\Search\Providers;

use App\Models\Supplier;
use App\Support\Search\DatabaseSearchProvider;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;

/**
 * Leverantörer: by name, supplier number, organisation number and email.
 */
final class SuppliersProvider extends DatabaseSearchProvider
{
    public const string KEY = 'suppliers';

    private const array COLUMNS = ['suppliers.name', 'suppliers.email', 'suppliers.org_number'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Leverantörer');
    }

    public function order(): int
    {
        return 21;
    }

    public function authorize(SearchContext $context): bool
    {
        return $context->allows('viewAny', Supplier::class);
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        if (! $this->wants($query)) {
            return null;
        }

        $builder = Supplier::query()->where('suppliers.company_id', $context->company->id)->select('suppliers.*');
        $identifiers = $this->orgNumber('suppliers', $query);

        if ($query->number !== null) {
            $identifiers[] = ['suppliers.number = ?', [$query->number]];
        }

        $this->whereText($builder, self::COLUMNS, $query, $this->anyOf($identifiers));
        $this->selectRank($builder, self::COLUMNS, $query, $identifiers);

        [$suppliers, $total] = $this->fetch($builder, 'suppliers.updated_at', $limit);

        return $this->group(
            $suppliers->map(fn (Supplier $supplier): SearchResult => $this->result($supplier)->withScore(self::rank($supplier)))->all(),
            $total,
            route('payables.suppliers', array_filter(['sok' => trim($query->text)])),
        );
    }

    public function find(string $id, SearchContext $context): ?SearchResult
    {
        $supplier = ctype_digit($id) ? Supplier::query()->find((int) $id) : null;

        return $supplier instanceof Supplier ? $this->result($supplier) : null;
    }

    private function result(Supplier $supplier): SearchResult
    {
        return new SearchResult(
            type: self::KEY,
            id: (string) $supplier->id,
            title: $supplier->name,
            url: route('payables.suppliers', ['sok' => $supplier->name, 'redigera' => $supplier->id]),
            subtitle: self::join(__('Leverantör :number', ['number' => $supplier->number]), $supplier->org_number, $supplier->email),
            badge: self::when(! $supplier->is_active, __('Inaktiv')),
            icon: 'supplier',
        );
    }
}
