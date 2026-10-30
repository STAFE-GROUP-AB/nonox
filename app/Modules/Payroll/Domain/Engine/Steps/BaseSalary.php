<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Modules\Payroll\Domain\Units;
use App\Modules\Payroll\Support\Calendar\SwedishHolidays;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Step 1: monthly salary × employment rate, for the part of the period the
 * person was employed. Hourly pay comes from the inputs (Additions).
 * Part months are prorated by calendar days, or by scheduled working days
 * (the week pattern, less public holidays), as the company has chosen.
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

        if (($context->settings['proration_method'] ?? null) === 'WORK_SCHEDULE') {
            $workdays = $this->workdays($context, $context->periodStart, $context->periodEnd);
            $worked = $this->workdays($context, $from, $to);
            $amount = $workdays === 0 ? 0 : BigDecimal::of($full)->multipliedBy($worked)->dividedBy($workdays, 0, RoundingMode::HalfUp)->toInt();

            $draft->add(new PayslipLineResult(self::CODE, __('Månadslön, :days av :total arbetsdagar', ['days' => $worked, 'total' => $workdays]), $amount, PayslipLineResult::TAX_TABLE, true, true,
                explanation: [...$explanation, 'prorated' => 'work_schedule', 'worked_days' => $worked, 'period_workdays' => $workdays]));

            return;
        }

        $amount = BigDecimal::of($full)->multipliedBy($employedDays)->dividedBy($periodDays, 0, RoundingMode::HalfUp)->toInt();

        $draft->add(new PayslipLineResult(self::CODE, __('Månadslön, :days av :total dagar', ['days' => $employedDays, 'total' => $periodDays]), $amount, PayslipLineResult::TAX_TABLE, true, true,
            explanation: [...$explanation, 'prorated' => 'calendar_days', 'employed_days' => $employedDays, 'period_days' => $periodDays]));
    }

    /**
     * Scheduled working days between two dates: days with hours in the week
     * pattern that are not public holidays.
     */
    private function workdays(PayrollContext $context, CarbonImmutable $from, CarbonImmutable $to): int
    {
        if ($context->weekPattern === null) {
            throw new PayrollBlockedException(__('Arbetsschema saknas på anställningen. Det behövs för delmånad räknad på arbetsdagar.'));
        }

        $days = 0;

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $hours = BigDecimal::of($context->weekPattern[$day->dayOfWeekIso - 1] ?? '0');

            if ($hours->isPositive() && ! SwedishHolidays::isHoliday($day)) {
                $days++;
            }
        }

        return $days;
    }
}
