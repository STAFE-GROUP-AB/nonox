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
     * @param  ?int  $percentBp  the collective agreement's percentage for procentregeln and semesterersättning, instead of the law's (NONOX Avtal, ADR-033 A9)
     * @param  ?int  $supplementBp  the agreement's semestertillägg per paid day under sammalöneregeln, in basis points of the monthly salary
     * @param  ?int  $minimumDailyOre  the agreement's lowest vacation pay per paid day under procentregeln, already scaled to the employment rate
     * @param  array<string, mixed>  $agreement  where the agreement's values come from, for the payslip explanation
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
        public ?int $percentBp = null,
        public ?int $supplementBp = null,
        public ?int $minimumDailyOre = null,
        public array $agreement = [],
    ) {}

    /**
     * A copy with a collective agreement's vacation values.
     *
     * @param  array<string, mixed>  $agreement
     */
    public function withAgreementTerms(?int $percentBp, ?int $supplementBp, ?int $minimumDailyOre, array $agreement): self
    {
        return new self(...[...get_object_vars($this), 'percentBp' => $percentBp, 'supplementBp' => $supplementBp, 'minimumDailyOre' => $minimumDailyOre, 'agreement' => $agreement]);
    }

    /** Paid days left: this year's earned days, then days saved from earlier years (18 §). */
    public function paidDaysLeft(): int
    {
        return $this->payoutWithSalary ? 0 : max(0, $this->paidDaysEarned + $this->savedDays - $this->daysTakenBefore);
    }
}
