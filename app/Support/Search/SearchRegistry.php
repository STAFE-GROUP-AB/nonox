<?php

declare(strict_types=1);

namespace App\Support\Search;

use App\Modules\ModuleManager;
use App\Support\Search\Contracts\SearchProvider;

/**
 * Every search provider, core and module (ADR-026). Which of them a user
 * gets is decided per company: a module's providers only while the module
 * is on, and each provider only when it authorises the user.
 */
final class SearchRegistry
{
    /** @var array<string, SearchProvider> */
    private array $providers = [];

    public function __construct(private readonly ModuleManager $modules) {}

    public function register(SearchProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    /** @return array<string, SearchProvider> */
    public function all(): array
    {
        return $this->providers;
    }

    /** @return array<string, SearchProvider> the providers the user may search, in order */
    public function for(SearchContext $context): array
    {
        $providers = array_filter(
            $this->providers,
            fn (SearchProvider $provider): bool => ($provider->module() === null || $this->modules->isEnabled($provider->module(), $context->company))
                && $provider->authorize($context),
        );

        uasort($providers, fn (SearchProvider $a, SearchProvider $b): int => $a->order() <=> $b->order());

        return $providers;
    }

    public function provider(string $key, SearchContext $context): ?SearchProvider
    {
        return $this->for($context)[$key] ?? null;
    }
}
