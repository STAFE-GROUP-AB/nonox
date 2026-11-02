<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

use Carbon\CarbonImmutable;

/**
 * One employee's day, plan against actual (PlanVsActual). The actual
 * intervals are the ones to pay: within the attest tolerance a time is the
 * planned one, otherwise the (rounded) checked-in time. Incomplete intervals
 * (a missing check-out) are kept so the day can say why it cannot be paid.
 */
final readonly class DayComparison
{
    public const string SOURCE_LEDGER = 'LEDGER';

    public const string SOURCE_PLAN = 'PLAN';

    public const string SOURCE_MANUAL = 'MANUAL';

    /**
     * @param  list<WorkInterval>  $planned
     * @param  list<WorkInterval>  $actual
     * @param  list<Deviation>  $deviations
     */
    public function __construct(
        public CarbonImmutable $date,
        public array $planned,
        public array $actual,
        public string $source,
        public array $deviations,
    ) {}

    public function plannedMinutes(): int
    {
        return array_sum(array_map(fn (WorkInterval $interval): int => $interval->workedMinutes(), $this->planned));
    }

    public function actualMinutes(): int
    {
        return array_sum(array_map(fn (WorkInterval $interval): int => $interval->workedMinutes(), $this->actual));
    }

    public function differenceMinutes(): int
    {
        return $this->actualMinutes() - $this->plannedMinutes();
    }

    /** @param  list<Deviation>  $deviations */
    public function with(array $deviations): self
    {
        return new self($this->date, $this->planned, $this->actual, $this->source, [...$this->deviations, ...$deviations]);
    }
}
