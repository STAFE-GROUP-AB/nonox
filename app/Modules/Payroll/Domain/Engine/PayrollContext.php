<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

use App\Modules\Payroll\Domain\Rules\RuleSet;
use Carbon\CarbonImmutable;

/**
 * Everything one payslip is calculated from: snapshots of the employee and
 * employment, the period, inputs, year-to-date values, settings and the
 * rules valid on the payment date. Same context, same result.
 */
final readonly class PayrollContext
{
    /**
     * @param  list<PayInput>  $inputs
     * @param  array<string, int>  $accumulators  calendar-year values before this payslip, by key
     * @param  array<string, mixed>  $settings
     * @param  list<AbsenceInput>  $absences  approved absences in the period
     * @param  array{last_sick_period_end?: string, last_sick_period_days?: int, karens_count_12m?: int}  $sickHistory
     * @param  ?list<string>  $weekPattern  scheduled hours Monday to Sunday
     * @param  ?array{below_bp: int, above_bp: int, name: string}  $pension  the employment's pension plan
     * @param  array<string, object>  $extensions  what other modules add for their steps, by module key (for example the agreement the employment is bound by)
     */
    public function __construct(
        public CarbonImmutable $birthDate,
        public string $taxResidence,
        public ?int $taxTable,
        public ?int $taxColumn,
        public ?int $taxAdjustmentPercentBp,
        public string $salaryForm,
        public ?int $monthlySalaryOre,
        public ?int $hourlyRateOre,
        public int $employmentRateBp,
        public CarbonImmutable $employmentStart,
        public ?CarbonImmutable $employmentEnd,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public CarbonImmutable $paymentDate,
        public array $inputs,
        public array $accumulators,
        public array $settings,
        public RuleSet $rules,
        public ?string $weeklyHours = null,
        public array $absences = [],
        public array $sickHistory = [],
        public ?array $weekPattern = null,
        public ?VacationInput $vacation = null,
        public ?array $pension = null,
        public array $extensions = [],
    ) {}

    /**
     * A copy with other inputs, for a module that prices inputs before the
     * calculation (PayrollExtensions).
     *
     * @param  list<PayInput>  $inputs
     */
    public function withInputs(array $inputs): self
    {
        return new self(...[...get_object_vars($this), 'inputs' => $inputs]);
    }

    /** A copy with what a module adds for its own steps. */
    public function withExtension(string $key, object $value): self
    {
        return new self(...[...get_object_vars($this), 'extensions' => [...$this->extensions, $key => $value]]);
    }

    public function extension(string $key): ?object
    {
        return $this->extensions[$key] ?? null;
    }
}
