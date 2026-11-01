<?php

declare(strict_types=1);

namespace App\Support\Search\Contracts;

use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;

/**
 * One kind of thing the spotlight can find (ADR-026). Core providers are
 * registered by SearchServiceProvider; a module registers its own from its
 * service provider and names itself in module(), so that a module that is
 * off for the company finds nothing.
 *
 * Every provider runs inside the current company (CompanyScope) and must
 * check the user's permission in authorize() before it is asked anything.
 */
interface SearchProvider
{
    /** The group key, also the result type: "vouchers", "payroll-employees". */
    public function key(): string;

    /** The group heading, in Swedish. */
    public function label(): string;

    /** The module key, or null for core bookkeeping. */
    public function module(): ?string;

    /** Where the group sits when no group has an exact hit; lower first. */
    public function order(): int;

    /** Whether the user may see this kind of record at all in the company. */
    public function authorize(SearchContext $context): bool;

    /**
     * At most $limit hits, best first, or null when the query is not for
     * this provider (a filter it cannot answer, or another type asked for).
     */
    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup;

    /** One record by id, for "Senast öppnade"; null when it is gone or not the user's to see. */
    public function find(string $id, SearchContext $context): ?SearchResult;

    /**
     * Shortcuts for "Att göra" when the field is empty: "3 leverantörsfakturor
     * att attestera". Each costs at most one small query.
     *
     * @return list<SearchResult>
     */
    public function todo(SearchContext $context): array;

    /**
     * Detail pages of this provider's records: route name => the route
     * parameter that holds the record. A visit is remembered as recently
     * opened.
     *
     * @return array<string, string>
     */
    public function detailRoutes(): array;
}
