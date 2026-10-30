<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

/**
 * The employee's vacation position when the payslip is calculated: the rule,
 * the paid days earned in the earning year (intjänandeår) for the current
 * vacation year (semesterår), the vacation days already taken in that year
 * and, for procentregeln, the vacation-pay basis of the earning year.
 */
final readonly class VacationInput
{
    public function __construct(
        public string $rule,
        public int $paidDaysEarned,
        public int $daysTakenBefore,
        public int $basisOre,
        public bool $payoutWithSalary,
    ) {}

    public function paidDaysLeft(): int
    {
        return $this->payoutWithSalary ? 0 : max(0, $this->paidDaysEarned - $this->daysTakenBefore);
    }
}
