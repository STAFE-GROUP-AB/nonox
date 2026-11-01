<?php

declare(strict_types=1);

namespace App\Support\Navigation;

/**
 * One page in a section of the sidebar, under its group label when it has one.
 */
final readonly class NavLink
{
    /** @param list<string> $patterns route name patterns that mean this page is shown */
    public function __construct(
        public string $label,
        public string $route,
        public array $patterns,
        public bool $active = false,
        public ?string $group = null,
    ) {}

    public function url(): string
    {
        return route($this->route);
    }
}
