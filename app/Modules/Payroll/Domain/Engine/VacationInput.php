<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

/**
 * The employee's vacation position when the payslip is calculated: the rule,
 * the paid days earned in the earning year (intjänandeår) for the current
 * vacation year (semesterår), days saved from earlier years, the vacation
 * days already taken in that year and, for procentregeln, the vacation-pay
 * basis of the earning year.
 */
final readonly class VacationInput
{
    /**
     * @param  list<array{description: string, amount_ore: int, closing_id: int}>  $yearEndPayouts  vacation pay from a closed semesterår, paid in this run (26, 26 a §§)
     * @param  ?array{amount_ore: int, days: int, earning: string}  $termination  semesterersättning when the employment ends in the period (28 §)
     */
    public function __construct(
        public string $rule,
        public int $paidDaysEarned,
        public int $daysTakenBefore,
        public int $basisOre,
        public bool $payoutWithSalary,
        public int $savedDays = 0,
        public array $yearEndPayouts = [],
        public string $countMethod = 'NET',
        public string $payoutTax = 'TABLE',
        public ?array $termination = null,
    ) {}

    /** Paid days left: this year's earned days, then days saved from earlier years (18 §). */
    public function paidDaysLeft(): int
    {
        return $this->payoutWithSalary ? 0 : max(0, $this->paidDaysEarned + $this->savedDays - $this->daysTakenBefore);
    }
}
