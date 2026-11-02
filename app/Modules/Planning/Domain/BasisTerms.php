<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

use App\Modules\Agreements\Domain\AgreementRules;

/**
 * What PayrollBasis needs to know about an employee on a day: the
 * employment in force (salary form, extent, wage), the checked agreement
 * version that covers the day, and whether the employment has OB included
 * in the salary. Overtime and mertid are counted per week
 * (PayrollBasis::week).
 */
final readonly class BasisTerms
{
    /**
     * @param  ?string  $salaryForm  MONTHLY or HOURLY; null when no employment is in force
     * @param  bool  $agreementUnverified  bound to an agreement that has no checked version for the day
     * @param  bool  $absence  approved absence in payroll on the day
     */
    public function __construct(
        public ?string $salaryForm,
        public int $employmentRateBp = 10000,
        public ?int $hourlyRateOre = null,
        public ?int $monthlySalaryOre = null,
        public ?AgreementRules $agreement = null,
        public bool $agreementUnverified = false,
        public bool $obIncluded = false,
        public bool $absence = false,
    ) {}
}
