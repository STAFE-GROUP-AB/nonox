<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Modules\Payroll\Domain\Units;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Step 1: monthly salary × employment rate, for the part of the period the
 * person was employed. Hourly pay comes from the inputs (Additions).
 * Part months are prorated by calendar days; proration by work schedule
 * needs the holiday calendar and blocks until it exists.
 */
final class BaseSalary implements Step
{
    public const string CODE = 'MANADSLON';

    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        if ($context->salaryForm !== 'MONTHLY' || $context->monthlySalaryOre === null) {
            return;
        }

        $full = BigDecimal::of($context->monthlySalaryOre)
            ->multipliedBy($context->employmentRateBp)
            ->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)
            ->toInt();

        $from = $context->employmentStart->greaterThan($context->periodStart) ? $context->employmentStart : $context->periodStart;
        $to = $context->employmentEnd !== null && $context->employmentEnd->lessThan($context->periodEnd) ? $context->employmentEnd : $context->periodEnd;

        if ($from->greaterThan($to)) {
            return;
        }

        $periodDays = (int) $context->periodStart->diffInDays($context->periodEnd) + 1;
        $employedDays = (int) $from->diffInDays($to) + 1;
        $explanation = ['monthly_salary_ore' => $context->monthlySalaryOre, 'employment_rate_bp' => $context->employmentRateBp];

        if ($employedDays === $periodDays) {
            $draft->add(new PayslipLineResult(self::CODE, __('Månadslön'), $full, PayslipLineResult::TAX_TABLE, true, true, explanation: $explanation));

            return;
        }

        if (($context->settings['proration_method'] ?? null) !== 'CALENDAR_DAYS') {
            throw new PayrollBlockedException(__('Delmånad räknad på arbetsschema stöds inte än. Välj kalenderdagar i löneinställningarna.'));
        }

        $amount = BigDecimal::of($full)->multipliedBy($employedDays)->dividedBy($periodDays, 0, RoundingMode::HalfUp)->toInt();

        $draft->add(new PayslipLineResult(self::CODE, __('Månadslön, :days av :total dagar', ['days' => $employedDays, 'total' => $periodDays]), $amount, PayslipLineResult::TAX_TABLE, true, true,
            explanation: [...$explanation, 'prorated' => 'calendar_days', 'employed_days' => $employedDays, 'period_days' => $periodDays]));
    }
}
