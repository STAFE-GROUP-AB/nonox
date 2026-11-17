<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Proposal;

/**
 * The engine's answer for one open shift: the proposed employee or none,
 * the score parts (lower is better) and the reasons as structured codes
 * with ids and counts only (ProposalReasons turns them into Swedish).
 */
final readonly class ProposalOutcome
{
    /**
     * @param  array<string, float>  $parts  criterion => part, before weighting
     * @param  list<array<string, int|string>>  $reasons
     */
    public function __construct(
        public string $slotId,
        public ?int $employeeId,
        public ?float $score,
        public array $parts,
        public array $reasons,
    ) {}

    public function isFilled(): bool
    {
        return $this->employeeId !== null;
    }
}
