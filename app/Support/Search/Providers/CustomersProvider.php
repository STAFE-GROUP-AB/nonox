<?php

declare(strict_types=1);

namespace App\Support\Search\Providers;

use App\Models\Customer;
use App\Support\Search\DatabaseSearchProvider;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;

/**
 * Kunder: by name, customer number, organisation number and email. The
 * personal number of a private customer is never searched or shown.
 */
final class CustomersProvider extends DatabaseSearchProvider
{
    public const string KEY = 'customers';

    private const array COLUMNS = ['customers.name', 'customers.email', 'customers.org_number'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Kunder');
    }

    public function order(): int
    {
        return 20;
    }

    public function authorize(SearchContext $context): bool
    {
        return $context->allows('viewAny', Customer::class);
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        if (! $this->wants($query)) {
            return null;
        }

        $builder = Customer::query()->where('customers.company_id', $context->company->id)->select('customers.*');
        $identifiers = $this->orgNumber('customers', $query);

        if ($query->number !== null) {
            $identifiers[] = ['customers.number = ?', [$query->number]];
        }

        $this->whereText($builder, self::COLUMNS, $query, $this->anyOf($identifiers));
        $this->selectRank($builder, self::COLUMNS, $query, $identifiers);

        [$customers, $total] = $this->fetch($builder, 'customers.updated_at', $limit);

        return $this->group(
            $customers->map(fn (Customer $customer): SearchResult => $this->result($customer)->withScore(self::rank($customer)))->all(),
            $total,
            route('invoicing.customers', array_filter(['sok' => trim($query->text)])),
        );
    }

    public function find(string $id, SearchContext $context): ?SearchResult
    {
        $customer = ctype_digit($id) ? Customer::query()->find((int) $id) : null;

        return $customer instanceof Customer ? $this->result($customer) : null;
    }

    private function result(Customer $customer): SearchResult
    {
        return new SearchResult(
            type: self::KEY,
            id: (string) $customer->id,
            title: $customer->name,
            url: route('invoicing.customers', ['sok' => $customer->name, 'redigera' => $customer->id]),
            subtitle: self::join(__('Kund :number', ['number' => $customer->number]), $customer->org_number, $customer->email),
            badge: self::when(! $customer->is_active, __('Inaktiv')),
            icon: 'customer',
        );
    }
}
