<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Proposal;

/** One run of the engine: an outcome per open shift, in time order, and how many swaps the improvement pass tried and kept. */
final readonly class ProposalResult
{
    /** @param  list<ProposalOutcome>  $outcomes */
    public function __construct(
        public array $outcomes,
        public int $swapsTried = 0,
        public int $swapsKept = 0,
    ) {}

    public function filled(): int
    {
        return count(array_filter($this->outcomes, fn (ProposalOutcome $outcome): bool => $outcome->isFilled()));
    }

    /** @return array<string, ?int> slot id => employee id */
    public function assignments(): array
    {
        $assignments = [];

        foreach ($this->outcomes as $outcome) {
            $assignments[$outcome->slotId] = $outcome->employeeId;
        }

        return $assignments;
    }
}
