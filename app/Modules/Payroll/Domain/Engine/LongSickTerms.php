<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

/**
 * An agreement's salary deduction per calendar day of sickness after the
 * employer's period, for a monthly salary (sick_pay.after_employer_period
 * in docs/avtal/datamodell.md §7): from fromDay to toDay of the sick
 * period, the deduction per calendar day is belowBp of the yearly salary
 * up to the threshold plus aboveBp of the part above it, divided by 365,
 * and never more than the yearly salary / 365. Days after toDay keep the
 * engine's ordinary hourly sick deduction.
 */
final readonly class LongSickTerms
{
    public function __construct(
        public int $fromDay,
        public int $toDay,
        public int $belowBp,
        public int $aboveBp,
        public int $thresholdYearlyOre,
        public string $source,
        public int $daysPerYear,
    ) {}
}
