<?php

declare(strict_types=1);

namespace App\Modules;

/**
 * Whether a module may be turned on or off: blockers stop it, warnings need
 * the user to confirm. Messages are translated.
 */
final readonly class ModuleCheck
{
    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $blockers = [],
        public array $warnings = [],
    ) {}

    public static function allow(): self
    {
        return new self;
    }

    public function allowed(): bool
    {
        return $this->blockers === [];
    }

    public function withBlocker(string $blocker): self
    {
        return new self([...$this->blockers, $blocker], $this->warnings);
    }
}
