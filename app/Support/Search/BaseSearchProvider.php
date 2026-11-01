<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Support\Search\Contracts\SearchProvider;

/**
 * Defaults for search providers: core, nothing to do, no detail pages.
 */
abstract class BaseSearchProvider implements SearchProvider
{
    public function module(): ?string
    {
        return null;
    }

    public function find(string $id, SearchContext $context): ?SearchResult
    {
        return null;
    }

    public function todo(SearchContext $context): array
    {
        return [];
    }

    public function detailRoutes(): array
    {
        return [];
    }

    /** The text when the condition holds, for a result's badge. */
    protected static function when(bool $condition, string $text): ?string
    {
        return $condition ? $text : null;
    }

    /** The parts that are there, joined with a middle dot: "Elverket AB · 2026-10-14". */
    protected static function join(?string ...$parts): string
    {
        return implode(' · ', array_filter($parts, fn (?string $part): bool => $part !== null && $part !== ''));
    }

    /** @param  array<int, SearchResult>  $results */
    protected function group(array $results, int $total, ?string $moreUrl = null): SearchGroup
    {
        return new SearchGroup($this->key(), $this->label(), array_values($results), $total, $this->order(), $moreUrl);
    }

    /**
     * Best first, at most $limit.
     *
     * @param  list<SearchResult>  $results
     */
    protected function best(array $results, int $limit): SearchGroup
    {
        usort($results, fn (SearchResult $a, SearchResult $b): int => $b->score <=> $a->score);

        return $this->group(array_slice($results, 0, $limit), count($results));
    }
}
