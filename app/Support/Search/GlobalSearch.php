<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Support\Search\Contracts\SearchProvider;
use App\Support\Search\Providers\ActionsProvider;
use App\Support\Tenancy\CurrentCompany;
use Generator;

/**
 * Runs the spotlight (ADR-026): asks every provider the user may search,
 * one at a time, and hands back each group as soon as it is ready, so the
 * controller can stream it. Providers held in memory (pages, actions,
 * companies) answer first; the kinds the user asked for come next.
 *
 * Every provider runs inside the context's company (CurrentCompany::run),
 * whatever the request resolved: CompanyScope filters every query on it.
 */
final class GlobalSearch
{
    public const int PER_GROUP = 5;

    public function __construct(
        private readonly SearchRegistry $registry,
        private readonly RecentItems $recent,
    ) {}

    /** @return Generator<int, SearchGroup> */
    public function stream(SearchQuery $query, SearchContext $context, int $limit = self::PER_GROUP): Generator
    {
        if ($query->isEmpty()) {
            yield from $this->suggestions($context);

            return;
        }

        $providers = CurrentCompany::run($context->company, fn (): array => $this->ordered($query, $context));

        foreach ($providers as $provider) {
            $group = CurrentCompany::run($context->company, fn (): ?SearchGroup => $provider->search($query, $context, $limit));

            if ($group !== null && ! $group->isEmpty()) {
                yield $group;
            }
        }
    }

    /** @return list<SearchGroup> */
    public function search(SearchQuery $query, SearchContext $context, int $limit = self::PER_GROUP): array
    {
        return iterator_to_array($this->stream($query, $context, $limit), false);
    }

    /**
     * The empty field: recently opened, things waiting, and quick actions.
     *
     * @return Generator<int, SearchGroup>
     */
    private function suggestions(SearchContext $context): Generator
    {
        $recent = CurrentCompany::run($context->company, fn (): array => $this->recent->list($context));

        if ($recent !== []) {
            yield new SearchGroup('recent', __('Senast öppnade'), $recent, count($recent), 0);
        }

        $todo = [];
        $providers = CurrentCompany::run($context->company, fn (): array => $this->registry->for($context));

        foreach ($providers as $provider) {
            array_push($todo, ...CurrentCompany::run($context->company, fn (): array => $provider->todo($context)));
        }

        if ($todo !== []) {
            yield new SearchGroup('todo', __('Att göra'), $todo, count($todo), 1);
        }

        $actions = $providers[ActionsProvider::KEY] ?? null;

        if ($actions instanceof ActionsProvider) {
            $group = CurrentCompany::run($context->company, fn (): SearchGroup => $actions->suggested($context));

            if (! $group->isEmpty()) {
                yield $group;
            }
        }
    }

    /** @return list<SearchProvider> */
    private function ordered(SearchQuery $query, SearchContext $context): array
    {
        $providers = array_values($this->registry->for($context));

        usort($providers, function (SearchProvider $a, SearchProvider $b) use ($query): int {
            $asked = fn (SearchProvider $provider): int => in_array($provider->key(), $query->types, true) ? 0 : 1;

            return [$a->order() >= 10 ? 1 : 0, $asked($a), $a->order()] <=> [$b->order() >= 10 ? 1 : 0, $asked($b), $b->order()];
        });

        return $providers;
    }
}
