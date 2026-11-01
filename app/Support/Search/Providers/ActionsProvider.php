<?php

declare(strict_types=1);

namespace App\Support\Search\Providers;

use App\Modules\ModuleManager;
use App\Support\Search\BaseSearchProvider;
use App\Support\Search\SearchAction;
use App\Support\Search\SearchActions;
use App\Support\Search\SearchContext;
use App\Support\Search\SearchGroup;
use App\Support\Search\SearchQuery;
use App\Support\Search\SearchResult;
use App\Support\Search\TextScore;

/**
 * "Skapa": the create actions of SearchActions the user may take, with
 * their module on.
 */
final class ActionsProvider extends BaseSearchProvider
{
    public const string KEY = 'actions';

    private const int SUGGESTED = 5;

    public function __construct(
        private readonly SearchActions $actions,
        private readonly ModuleManager $modules,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Skapa');
    }

    public function order(): int
    {
        return 2;
    }

    public function authorize(SearchContext $context): bool
    {
        return true;
    }

    public function search(SearchQuery $query, SearchContext $context, int $limit): ?SearchGroup
    {
        $text = $query->navigationText();

        if ($text === '') {
            return null;
        }

        $results = [];

        foreach ($this->available($context) as $action) {
            $score = TextScore::score(__($action->label), $text, $action->keywords);

            if ($score !== null) {
                $results[] = $this->result($action, $context)->withScore($score);
            }
        }

        return $this->best($results, $limit);
    }

    /** The most used actions, for the empty field. */
    public function suggested(SearchContext $context): SearchGroup
    {
        $actions = array_filter($this->available($context), fn (SearchAction $action): bool => $action->priority > 0);
        usort($actions, fn (SearchAction $a, SearchAction $b): int => $a->priority <=> $b->priority);
        $results = array_map(fn (SearchAction $action): SearchResult => $this->result($action, $context), array_slice($actions, 0, self::SUGGESTED));

        return new SearchGroup('shortcuts', __('Snabbval'), $results, count($results), 2);
    }

    /** @return list<SearchAction> */
    private function available(SearchContext $context): array
    {
        return array_values(array_filter(
            $this->actions->all(),
            fn (SearchAction $action): bool => ($action->module === null || $this->modules->isEnabled($action->module, $context->company))
                && ($action->permission === null || $context->can($action->permission)),
        ));
    }

    private function result(SearchAction $action, SearchContext $context): SearchResult
    {
        $parameters = array_map(fn (int|string $value): int|string => $value === '{company}' ? $context->company->id : $value, $action->parameters);

        return new SearchResult(
            type: self::KEY,
            id: $action->key,
            title: __($action->label),
            url: route($action->route, [...$parameters, ...$action->query]),
            icon: $action->icon,
            remember: false,
        );
    }
}
