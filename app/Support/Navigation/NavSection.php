<?php

declare(strict_types=1);

namespace App\Support\Navigation;

/**
 * A top-level item in the sidebar. Its pages are only resolved for the
 * section that is open, so a section never costs a query unless it is shown.
 */
final readonly class NavSection
{
    /**
     * @param  list<string>  $patterns  route name patterns that belong to the section
     * @param  list<NavLink>  $children
     */
    public function __construct(
        public string $label,
        public string $route,
        public array $patterns,
        public bool $active = false,
        public array $children = [],
    ) {}

    public function url(): string
    {
        return route($this->route);
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    /** The page the user is on, when it is one of the section's pages. */
    public function current(): ?NavLink
    {
        foreach ($this->children as $child) {
            if ($child->active) {
                return $child;
            }
        }

        return null;
    }

    /** @return list<array{label: ?string, links: list<NavLink>}> the pages in order, one entry per group */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->children as $child) {
            $last = array_key_last($groups);

            if ($last === null || $groups[$last]['label'] !== $child->group) {
                $groups[] = ['label' => $child->group, 'links' => [$child]];

                continue;
            }

            $groups[$last]['links'][] = $child;
        }

        return $groups;
    }
}
