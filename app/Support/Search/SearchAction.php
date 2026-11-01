<?php

declare(strict_types=1);

namespace App\Support\Search;

/**
 * Something to create or start from the spotlight: "Ny faktura", "Lägg till
 * kund". Core actions are registered by SearchServiceProvider, a module's by
 * its own service provider with its module key.
 */
final readonly class SearchAction
{
    /**
     * @param  ?string  $permission  needed to see the action; null for every member
     * @param  array<string, int|string>  $parameters  route parameters; '{company}' is the current company's id
     * @param  array<string, int|string>  $query  query string, such as ['ny' => 1] to open the form at once
     * @param  list<string>  $keywords  other words the action answers to
     * @param  int  $priority  lower is shown earlier among the suggestions on an empty field; 0 = not suggested
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $route,
        public ?string $permission = null,
        public array $parameters = [],
        public array $query = [],
        public array $keywords = [],
        public ?string $module = null,
        public int $priority = 0,
        public string $icon = 'plus',
    ) {}
}
