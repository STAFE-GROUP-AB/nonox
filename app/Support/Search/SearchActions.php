<?php

declare(strict_types=1);

namespace App\Support\Search;

/**
 * The registry of create actions the spotlight offers (ADR-026).
 */
final class SearchActions
{
    /** @var array<string, SearchAction> */
    private array $actions = [];

    public function register(SearchAction ...$actions): void
    {
        foreach ($actions as $action) {
            $this->actions[$action->key] = $action;
        }
    }

    /** @return array<string, SearchAction> */
    public function all(): array
    {
        return $this->actions;
    }
}
