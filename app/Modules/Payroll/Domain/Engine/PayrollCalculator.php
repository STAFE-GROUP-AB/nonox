<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

use App\Modules\Payroll\Domain\Engine\Steps\Accumulators;
use App\Modules\Payroll\Domain\Engine\Steps\Additions;
use App\Modules\Payroll\Domain\Engine\Steps\BaseSalary;
use App\Modules\Payroll\Domain\Engine\Steps\EmployerContributions;
use App\Modules\Payroll\Domain\Engine\Steps\NetItems;
use App\Modules\Payroll\Domain\Engine\Steps\NetPay;
use App\Modules\Payroll\Domain\Engine\Steps\WithholdingTaxStep;

/**
 * Calculates one payslip from a context (PAYROLL_MODULE.md B.9). No side
 * effects: storing the result is CalculatePayrollRun's job. Absence, sick
 * pay, vacation, benefits, allowances, gross deductions and pension (steps
 * 2–4, 6–8 and 12) are added in later tasks.
 */
final class PayrollCalculator
{
    /** @var list<Step> */
    private array $steps;

    public function __construct(
        BaseSalary $baseSalary,
        Additions $additions,
        WithholdingTaxStep $tax,
        NetItems $netItems,
        EmployerContributions $contributions,
        NetPay $netPay,
        Accumulators $accumulators,
    ) {
        $this->steps = [$baseSalary, $additions, $tax, $netItems, $contributions, $netPay, $accumulators];
    }

    /**
     * @throws PayrollBlockedException
     */
    public function calculate(PayrollContext $context): PayslipDraft
    {
        $draft = new PayslipDraft;

        foreach ($this->steps as $step) {
            $step->apply($context, $draft);
        }

        return $draft;
    }
}
