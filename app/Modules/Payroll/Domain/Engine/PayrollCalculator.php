<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

use App\Modules\Payroll\Domain\Engine\Steps\AbsenceDeductions;
use App\Modules\Payroll\Domain\Engine\Steps\Accumulators;
use App\Modules\Payroll\Domain\Engine\Steps\Additions;
use App\Modules\Payroll\Domain\Engine\Steps\Allowances;
use App\Modules\Payroll\Domain\Engine\Steps\BaseSalary;
use App\Modules\Payroll\Domain\Engine\Steps\EmployerContributions;
use App\Modules\Payroll\Domain\Engine\Steps\NetItems;
use App\Modules\Payroll\Domain\Engine\Steps\NetPay;
use App\Modules\Payroll\Domain\Engine\Steps\Pension;
use App\Modules\Payroll\Domain\Engine\Steps\SickPay;
use App\Modules\Payroll\Domain\Engine\Steps\Vacation;
use App\Modules\Payroll\Domain\Engine\Steps\WithholdingTaxStep;

/**
 * Calculates one payslip from a context (PAYROLL_MODULE.md B.9). No side
 * effects: storing the result is CalculatePayrollRun's job. Gross
 * deductions (step 8, salary exchange) are not yet supported.
 */
final class PayrollCalculator
{
    /** @var list<Step> */
    private array $steps;

    public function __construct(
        BaseSalary $baseSalary,
        AbsenceDeductions $absences,
        SickPay $sickPay,
        Additions $additions,
        Vacation $vacation,
        Allowances $allowances,
        WithholdingTaxStep $tax,
        NetItems $netItems,
        EmployerContributions $contributions,
        Pension $pension,
        NetPay $netPay,
        Accumulators $accumulators,
    ) {
        $this->steps = [$baseSalary, $absences, $sickPay, $additions, $vacation, $allowances, $tax, $netItems, $contributions, $pension, $netPay, $accumulators];
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
