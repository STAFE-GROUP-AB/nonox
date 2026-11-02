<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

/**
 * An agreement's salary deduction per calendar day of sickness after the
 * employer's period, for a monthly salary (sick_pay.after_employer_period
 * in docs/avtal/datamodell.md §7): from fromDay to toDay of the sick
 * period, the deduction per calendar day is belowBp of the yearly salary
 * up to the threshold plus aboveBp of the part above it, divided by
 * daysPerYear, and never more than the fixed yearly salary / daysPerYear.
 * Days after toDay, and days past the ceiling of maxDaysIn12Months days
 * from fromDay in the twelve months back from the sick period's first day,
 * keep the engine's ordinary hourly sick deduction.
 *
 * The yearly salary is (monthly × rate + extraMonthlyOre) × 12: the
 * agreement's "månadslön" adds fixed supplements, the monthly average of
 * variable pay and benefits where its data says so. The ceiling per day
 * uses the fixed salary: monthly × rate + capExtraMonthlyOre (the fixed
 * supplements).
 */
final readonly class LongSickTerms
{
    /**
     * @param  array<string, mixed>  $basisExplanation  what extraMonthlyOre is made of, for the payslip line
     * @param  list<string>  $warnings  shown on the payslip when a day from fromDay is deducted
     */
    public function __construct(
        public int $fromDay,
        public int $toDay,
        public int $belowBp,
        public int $aboveBp,
        public int $thresholdYearlyOre,
        public string $source,
        public int $daysPerYear,
        public ?int $maxDaysIn12Months = null,
        public int $extraMonthlyOre = 0,
        public int $capExtraMonthlyOre = 0,
        public array $basisExplanation = [],
        public array $warnings = [],
    ) {}
}
