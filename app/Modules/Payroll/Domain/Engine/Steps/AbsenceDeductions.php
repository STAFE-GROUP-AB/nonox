<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\HourlyValue;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Modules\Payroll\Domain\Units;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Step 2: unpaid absence (leave, VAB, parental leave and other unpaid
 * types) deducted from a monthly salary by the hours missed. Hourly staff
 * are simply not paid for hours they did not work. Sick days are step 3;
 * vacation is its own step.
 */
final class AbsenceDeductions implements Step
{
    public const array UNPAID = ['LEAVE_UNPAID', 'VAB', 'PARENTAL', 'STUDY', 'MILITARY', 'OTHER'];

    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        if ($context->salaryForm !== 'MONTHLY') {
            return;
        }

        $byType = [];

        foreach ($context->absences as $absence) {
            if (in_array($absence->type, self::UNPAID, true)) {
                $byType[$absence->type] = ($byType[$absence->type] ?? BigDecimal::zero())->plus($absence->hours);
            }
        }

        if ($byType === []) {
            return;
        }

        $hourValue = HourlyValue::hour($context);

        foreach ($byType as $type => $hours) {
            $amount = $hours->multipliedBy($hourValue)->toScale(0, RoundingMode::HalfUp)->toInt();

            $draft->add(new PayslipLineResult('FRANVAROAVDRAG', __('Avdrag för frånvaro, :hours timmar', ['hours' => (string) $hours]), -$amount, PayslipLineResult::TAX_TABLE, true, true, (string) $hours, $hourValue->toScale(0, RoundingMode::HalfUp)->toInt(),
                explanation: ['type' => $type, 'hours' => (string) $hours, 'hour_value_ore' => (string) $hourValue->toScale(Units::SHOWN_DECIMALS, RoundingMode::HalfUp)]));
        }
    }
}
