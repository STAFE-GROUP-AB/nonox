<?php

declare(strict_types=1);

namespace App\Domain\Group;

/**
 * What "Uppdatera koncernmarkeringar" would do (RefreshIntercompanyTags):
 * shown to a person first, applied only when they confirm. Each entry is a
 * voucher row id; $add and $change carry the kind and organisation number
 * the row gets.
 */
final readonly class IntercompanyTagPlan
{
    /**
     * @param  array<int, array{kind: IntercompanyKind, org: string}>  $add  voucher row id => tag
     * @param  array<int, array{kind: IntercompanyKind, org: string}>  $change  tag id => new tag
     * @param  list<int>  $remove  tag ids
     * @param  int  $kept  rows a person tagged by hand, which the rule leaves alone
     * @param  int  $inClosedYear  rows added, changed or removed that are in a closed fiscal year
     * @param  int  $inLockedPeriod  the same, in a locked period
     */
    public function __construct(
        public array $add,
        public array $change,
        public array $remove,
        public int $kept,
        public int $inClosedYear = 0,
        public int $inLockedPeriod = 0,
    ) {}

    public function isEmpty(): bool
    {
        return $this->add === [] && $this->change === [] && $this->remove === [];
    }

    /** @return array{added: int, changed: int, removed: int, kept: int} */
    public function counts(): array
    {
        return ['added' => count($this->add), 'changed' => count($this->change), 'removed' => count($this->remove), 'kept' => $this->kept];
    }

    /** @return array{in_closed_year: int, in_locked_period: int} */
    public function flags(): array
    {
        return ['in_closed_year' => $this->inClosedYear, 'in_locked_period' => $this->inLockedPeriod];
    }
}
