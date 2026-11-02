<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\AbsenceInput;
use App\Modules\Payroll\Domain\Engine\HourlyValue;
use App\Modules\Payroll\Domain\Engine\LongSickTerms;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Modules\Payroll\Domain\Units;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Step 3: sick pay under sjuklönelagen (rules sick_pay.*). A monthly salary
 * loses the pay for the sick hours (sjukavdrag); for the days of the
 * employer's period the employee gets the sick-pay percentage of that pay,
 * less one karensavdrag per new sick period: the karensavdrag percentage of
 * an average week's sick pay, at most as often as the rule allows in twelve
 * months. After the employer's period there is no sick pay and the payslip
 * flags Försäkringskassan. A new sick period starting within the
 * reinfection window continues the previous one.
 *
 * With SickPayTerms from another module (A8): the OB the published
 * schedule would have paid on the sick days of the employer's period is
 * paid with the sick-pay percentage (sjuklönelagen 6 §, "anställnings-
 * förmåner"); the average week behind the karensavdrag includes the average
 * weekly OB and, for irregular hours without weekly hours, the schedule's
 * average weekly hours; and an agreement's deduction per calendar day
 * replaces the hourly deduction from its first to its last day.
 *
 * TODO(legal-verify): a karensavdrag larger than the sick pay in the
 * payslip (here capped at it, the rest not carried), and karensavdrag after
 * reinfection.
 */
final class SickPay implements Step
{
    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        $days = array_values(array_filter($context->absences, fn (AbsenceInput $absence): bool => $absence->type === 'SICK'));

        if ($days === []) {
            return;
        }

        usort($days, fn (AbsenceInput $a, AbsenceInput $b): int => $a->date <=> $b->date);

        $terms = $context->sickPayTerms;

        if ($context->weeklyHours === null && $terms?->averageWeeklyHours !== null) {
            $context = new PayrollContext(...[...get_object_vars($context), 'weeklyHours' => $terms->averageWeeklyHours]);
        }

        $rules = $context->rules;
        $percentBp = (int) ((array) $rules->get('sick_pay.percentage'))['bp'];
        $karensBp = (int) ((array) $rules->get('sick_pay.karensavdrag_percent_of_weekly'))['bp'];
        $maxKarens = (int) (((array) $rules->get('sick_pay.karensavdrag_percent_of_weekly'))['max_occasions_per_12_months'] ?? PHP_INT_MAX);
        $employerDays = (int) ((array) $rules->get('sick_pay.employer_period_days'))['days'];
        $window = (int) ((array) $rules->get('sick_pay.reinfection_window_days'))['days'];
        $hourValue = HourlyValue::hour($context);
        $longSick = $context->salaryForm === 'MONTHLY' ? $terms?->longSick : null;

        $karensCount = (int) ($context->sickHistory['karens_count_12m'] ?? 0);
        $previousEnd = isset($context->sickHistory['last_sick_period_end']) ? CarbonImmutable::parse($context->sickHistory['last_sick_period_end']) : null;
        $previousDays = (int) ($context->sickHistory['last_sick_period_days'] ?? 0);
        $lost = BigDecimal::zero();
        $paidHours = BigDecimal::zero();
        $unpaidHours = BigDecimal::zero();
        $karensPeriods = 0;
        $supplements = 0;
        $supplementDays = [];
        $longDays = 0;
        $longDeduction = 0;

        foreach ($this->spells($days) as $spell) {
            $start = $spell[0]->date;
            $end = $spell[count($spell) - 1]->date;
            $reinfection = $previousEnd !== null && (int) $previousEnd->diffInDays($start) <= $window;
            $offset = $reinfection ? $previousDays : 0;

            if (! $reinfection && $karensCount < $maxKarens) {
                $karensPeriods++;
                $karensCount++;
            }

            foreach ($spell as $day) {
                $hours = BigDecimal::of($day->hours);
                $number = $offset + (int) $start->diffInDays($day->date) + 1;

                if ($longSick !== null && $number >= $longSick->fromDay && $number <= $longSick->toDay) {
                    $longDays++;
                    $longDeduction += $this->calendarDayDeduction($context, $longSick);

                    continue;
                }

                $lost = $lost->plus($hours->multipliedBy($hourValue));

                if ($number <= $employerDays) {
                    $paidHours = $paidHours->plus($hours);

                    if ($day->lostSupplementsOre > 0) {
                        $supplements += $day->lostSupplementsOre;
                        $supplementDays[$day->date->toDateString()] = $day->supplements;
                    }

                    continue;
                }

                $unpaidHours = $unpaidHours->plus($hours);
            }

            $previousEnd = $end;
            $previousDays = $offset + (int) $start->diffInDays($end) + 1;
        }

        $sickPay = $paidHours->multipliedBy($hourValue)->multipliedBy($percentBp)->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt();
        $supplementSickPay = BigDecimal::of($supplements)->multipliedBy($percentBp)->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt();
        $week = HourlyValue::week($context)->plus($terms === null ? 0 : ($terms->averageWeeklySupplementsOre ?? 0));
        $weeklySickPay = $week->multipliedBy($percentBp)->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp);
        $karens = min($sickPay + $supplementSickPay, $weeklySickPay->multipliedBy($karensBp)->multipliedBy($karensPeriods)->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt());

        if ($context->salaryForm === 'MONTHLY') {
            $draft->add(new PayslipLineResult('SJUKAVDRAG', __('Sjukavdrag'), -$lost->toScale(0, RoundingMode::HalfUp)->toInt(), PayslipLineResult::TAX_TABLE, true, true,
                explanation: ['hours' => (string) $paidHours->plus($unpaidHours), 'hour_value_ore' => (string) $hourValue->toScale(Units::SHOWN_DECIMALS, RoundingMode::HalfUp)]));
        }

        if ($longDeduction > 0 && $longSick !== null) {
            $draft->add(new PayslipLineResult('SJUKAVDRAG', __('Sjukavdrag dag :from–:to enligt avtal, :days kalenderdagar', ['from' => $longSick->fromDay, 'to' => $longSick->toDay, 'days' => $longDays]), -$longDeduction, PayslipLineResult::TAX_TABLE, true, true,
                explanation: ['days' => $longDays, 'below_bp' => $longSick->belowBp, 'above_bp' => $longSick->aboveBp, 'threshold_yearly_ore' => $longSick->thresholdYearlyOre, 'source' => $longSick->source]));
        }

        if ($sickPay > 0) {
            $draft->add(new PayslipLineResult('SJUKLON', __('Sjuklön :percent %, :hours timmar', ['percent' => $percentBp / Units::ORE, 'hours' => (string) $paidHours]), $sickPay, PayslipLineResult::TAX_TABLE, true, true,
                ruleVersionId: $rules->version('sick_pay.percentage')->id, explanation: ['hours' => (string) $paidHours, 'rate_bp' => $percentBp]));
        }

        if ($supplementSickPay > 0) {
            $draft->add(new PayslipLineResult('SJUKLON', __('Sjuklön :percent % av OB enligt schemat', ['percent' => $percentBp / Units::ORE]), $supplementSickPay, PayslipLineResult::TAX_TABLE, true, true,
                ruleVersionId: $rules->version('sick_pay.percentage')->id,
                explanation: ['lost_supplements_ore' => $supplements, 'rate_bp' => $percentBp, 'days' => $supplementDays, ...($terms === null ? [] : $terms->explanation)]));
        }

        if ($karens > 0) {
            $draft->add(new PayslipLineResult('KARENSAVDRAG', __('Karensavdrag'), -$karens, PayslipLineResult::TAX_TABLE, true, true,
                ruleVersionId: $rules->version('sick_pay.karensavdrag_percent_of_weekly')->id,
                explanation: array_filter([
                    'weekly_sick_pay_ore' => (string) $weeklySickPay->toScale(Units::SHOWN_DECIMALS, RoundingMode::HalfUp),
                    'rate_bp' => $karensBp,
                    'periods' => $karensPeriods,
                    'average_weekly_supplements_ore' => $terms?->averageWeeklySupplementsOre,
                    'average_weekly_hours' => $terms?->averageWeeklyHours,
                ], fn (mixed $value): bool => $value !== null)));
        }

        if ($unpaidHours->isPositive() || $longDays > 0) {
            $draft->warnings[] = __('Sjukperioden är längre än arbetsgivarperioden. Anmäl sjukfallet till Försäkringskassan.');
        }
    }

    /**
     * The agreement's deduction for one calendar day of long sickness: the
     * share of the yearly salary below and above the threshold, per day,
     * never more than a day's salary.
     */
    private function calendarDayDeduction(PayrollContext $context, LongSickTerms $terms): int
    {
        $yearly = BigDecimal::of((int) $context->monthlySalaryOre)
            ->multipliedBy($context->employmentRateBp)
            ->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp)
            ->multipliedBy(Units::MONTHS_PER_YEAR);
        $below = BigDecimal::min($yearly, BigDecimal::of($terms->thresholdYearlyOre));
        $above = BigDecimal::max(BigDecimal::zero(), $yearly->minus($terms->thresholdYearlyOre));
        $deduction = $below->multipliedBy($terms->belowBp)->plus($above->multipliedBy($terms->aboveBp))
            ->dividedBy(Units::BASIS_POINTS * $terms->daysPerYear, Units::SCALE, RoundingMode::HalfUp);
        $cap = $yearly->dividedBy($terms->daysPerYear, Units::SCALE, RoundingMode::HalfUp);

        return BigDecimal::min($deduction, $cap)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * Sick days grouped into spells of consecutive calendar days.
     *
     * @param  list<AbsenceInput>  $days  sorted by date
     * @return list<non-empty-list<AbsenceInput>>
     */
    private function spells(array $days): array
    {
        $spells = [];

        foreach ($days as $day) {
            $last = array_key_last($spells);

            if ($last !== null && (int) $spells[$last][count($spells[$last]) - 1]->date->diffInDays($day->date) <= 1) {
                $spells[$last][] = $day;

                continue;
            }

            $spells[] = [$day];
        }

        return $spells;
    }
}
