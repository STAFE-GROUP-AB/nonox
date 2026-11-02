<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Domain\DayBasis;
use App\Modules\Planning\Domain\DayComparison;
use App\Modules\Planning\Domain\Deviation;
use App\Modules\Planning\Domain\WeekBasis;
use App\Modules\Planning\Models\Attestation;
use Carbon\CarbonImmutable;

/**
 * One employee's day on "Tid att attestera": plan against actual, the
 * payroll basis and what stands in the way. An attested or sent day shows
 * what was frozen when it was attested.
 */
final readonly class AttestRow
{
    /**
     * @param  list<Deviation>  $deviations
     * @param  ?string  $lockReason  why the day cannot be sent to payroll (its month's run is attested)
     * @param  ?WeekBasis  $week  the week's overtime and mertid, on the last day of a fully attested week
     * @param  list<Deviation>  $weekDeviations  what the week says on its last day; never frozen with the day
     */
    public function __construct(
        public Employee $employee,
        public CarbonImmutable $date,
        public DayComparison $comparison,
        public DayBasis $basis,
        public array $deviations,
        public ?Attestation $attestation,
        public ?string $lockReason = null,
        public ?WeekBasis $week = null,
        public array $weekDeviations = [],
    ) {}

    /** @param  list<Deviation>  $deviations */
    public function withWeek(?WeekBasis $week, array $deviations): self
    {
        return new self($this->employee, $this->date, $this->comparison, $this->basis, $this->deviations, $this->attestation, $this->lockReason, $week, $deviations);
    }

    /**
     * The day's deviations and the week's.
     *
     * @return list<Deviation>
     */
    public function allDeviations(): array
    {
        return [...$this->deviations, ...$this->weekDeviations];
    }

    public function key(): string
    {
        return "{$this->employee->id}|{$this->date->toDateString()}";
    }

    public function status(): string
    {
        return $this->attestation === null ? Attestation::OPEN : $this->attestation->status;
    }

    public function isOpen(): bool
    {
        return $this->status() === Attestation::OPEN;
    }

    public function isBlocked(): bool
    {
        foreach ($this->deviations as $deviation) {
            if ($deviation->blocking) {
                return true;
            }
        }

        return false;
    }

    /** Whether the day differs from the plan in a way the manager should look at. */
    public function hasDeviations(): bool
    {
        foreach ($this->allDeviations() as $deviation) {
            if (! $deviation->informational) {
                return true;
            }
        }

        return false;
    }

    public function canAttest(): bool
    {
        return $this->isOpen() && ! $this->isBlocked();
    }
}
