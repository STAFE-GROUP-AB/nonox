<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

/** A person in the plan: their free working time and the skills they have. */
final readonly class PlanPerson
{
    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     * @param  list<int>  $skillIds
     */
    public function __construct(
        public int $id,
        public array $intervals,
        public array $skillIds,
    ) {}

    public function has(?int $skillId): bool
    {
        return $skillId === null || in_array($skillId, $this->skillIds, true);
    }
}
