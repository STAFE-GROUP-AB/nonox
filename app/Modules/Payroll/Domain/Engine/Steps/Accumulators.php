<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\Step;

/**
 * Step 14: the calendar-year totals this payslip adds.
 */
final class Accumulators implements Step
{
    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        $benefits = array_sum(array_map(fn (PayslipLineResult $line): int => $line->amountOre, array_values(array_filter($draft->lines, fn (PayslipLineResult $line): bool => (bool) ($line->explanation['benefit'] ?? false)))));

        foreach ([
            'gross_ytd' => $draft->gross(),
            'tax_ytd' => $draft->taxOre,
            'contribution_basis_ytd' => $draft->contributionBasis(),
            'benefit_ytd' => $benefits,
        ] as $key => $value) {
            $draft->accumulatorDeltas[$key] = ($draft->accumulatorDeltas[$key] ?? 0) + $value;
        }
    }
}
