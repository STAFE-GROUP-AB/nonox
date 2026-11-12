<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

/**
 * A production order as the scheduler sees it: what sorts it (§13.3
 * step 3), when it may start at the earliest (its material ready date and
 * planned start), its promised date and its operations in sequence.
 */
final readonly class PlanOrder
{
    /**
     * @param  list<PlanOperation>  $operations
     */
    public function __construct(
        public int $id,
        public int $number,
        public array $operations,
        public ?int $priority = null,
        public ?string $promisedOn = null,
        public ?int $releasedAt = null,
        public ?int $earliestStart = null,
        public ?string $materialReadyOn = null,
        public bool $materialAssumed = false,
        public ?string $salesDocumentId = null,
    ) {}

    /** The total order of §13.3 step 3: manual priority, promised date (none last), release time, number. */
    public static function compare(self $a, self $b): int
    {
        return [$a->priority ?? PHP_INT_MAX, $a->promisedOn ?? '9999-12-31', $a->releasedAt ?? PHP_INT_MAX, $a->number, $a->id]
            <=> [$b->priority ?? PHP_INT_MAX, $b->promisedOn ?? '9999-12-31', $b->releasedAt ?? PHP_INT_MAX, $b->number, $b->id];
    }
}
