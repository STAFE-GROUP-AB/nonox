<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\AbsenceInput;
use App\Modules\Payroll\Domain\Engine\HourlyValue;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Modules\Payroll\Domain\Units;
use App\Modules\Payroll\Support\Calendar\VacationDays;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Step 4 (PAYROLL_MODULE.md B.9), after the inputs so the vacation-pay basis
 * of this payslip is known: vacation days taken in the period, paid from the
 * days earned and unpaid beyond them (semesterlagen 7, 16 a, 16 b §§).
 *
 * - Sammalöneregeln, monthly salary: the salary continues and each paid day
 *   adds the supplement, 0.43 % of the monthly salary (16 a §).
 * - Procentregeln: each paid day is paid with 12 % of the earning year's
 *   vacation-pay basis divided by the paid days earned (16 b §); a monthly
 *   salary is reduced for the hours of those days.
 * - Unpaid days reduce a monthly salary by the hours missed; hourly staff are
 *   simply not paid for hours not worked.
 * - Semesterersättning with every salary: 12 % of this payslip's
 *   vacation-pay basis, and no paid days (5 §, or a collective agreement).
 * - After a semesterår is closed: pay for unused days that were not saved
 *   (26 a §) and vacation pay on variable pay (16 a, 26 §§), taxed as an
 *   engångsbelopp.
 *
 * Saved days are paid at this year's value. TODO(legal-verify): the value of
 * saved days under procentregeln.
 *
 * A collective agreement (NONOX Avtal, ADR-033 A9) can give a higher
 * percentage, a higher semestertillägg and a lowest vacation pay per day
 * (VacationInput::withAgreementTerms); the line then names the agreement.
 * Year-end payouts and pay when the employment ends are still the law's
 * values (VacationLedger).
 */
final class Vacation implements Step
{
    public function apply(PayrollContext $context, PayslipDraft $draft): void
    {
        $vacation = $context->vacation;

        if ($vacation === null) {
            return;
        }

        if ($vacation->payoutWithSalary) {
            $this->payout($context, $draft);
        }

        foreach ($vacation->yearEndPayouts as $payout) {
            // Paid after the semesterår, not with the vacation itself: an engångsbelopp.
            $draft->add(new PayslipLineResult('SEMESTERLON', $payout['description'], $payout['amount_ore'], PayslipLineResult::TAX_ONE_OFF, true, false,
                explanation: ['vacation_year_closing_id' => $payout['closing_id']]));
        }

        if ($vacation->termination !== null) {
            // TODO(legal-verify): semesterersättning when the employment ends is taxed as an engångsbelopp.
            $draft->add(new PayslipLineResult('SEMESTERERSATTNING', __('Semesterersättning vid anställningens slut, :days dagar och intjänad semester', ['days' => $vacation->termination['days']]),
                $vacation->termination['amount_ore'], PayslipLineResult::TAX_ONE_OFF, true, false,
                explanation: ['termination' => true, 'days' => $vacation->termination['days'], 'earning' => $vacation->termination['earning']]));
        }

        $days = $this->semesterdagar($context);

        if ($days === []) {
            return;
        }

        $paid = array_slice($days, 0, $vacation->paidDaysLeft());
        $unpaid = array_slice($days, count($paid));
        $monthly = $context->salaryForm === 'MONTHLY';
        $rule = $monthly ? $vacation->rule : 'PROCENTREGELN';

        if ($paid !== [] && $rule === 'SAMMALONEREGELN') {
            $this->supplement($context, $draft, count($paid));
        }

        if ($paid !== [] && $rule === 'PROCENTREGELN') {
            $this->percentPay($context, $draft, $paid, $monthly);
        }

        if ($unpaid !== [] && $monthly) {
            $hours = $this->hours($unpaid);
            $hourValue = HourlyValue::hour($context);
            $draft->add(new PayslipLineResult('OBETALD_SEMESTER', __('Obetald semester'), -$hours->multipliedBy($hourValue)->toScale(0, RoundingMode::HalfUp)->toInt(), PayslipLineResult::TAX_TABLE, true, false,
                (string) $hours, $hourValue->toScale(0, RoundingMode::HalfUp)->toInt(), explanation: ['days' => count($unpaid), 'hours' => (string) $hours]));
        }

        if ($unpaid !== []) {
            $draft->warnings[] = __(':count semesterdagar saknar betald semester och räknas som obetald semester.', ['count' => count($unpaid)]);
        }
    }

    private function supplement(PayrollContext $context, PayslipDraft $draft, int $days): void
    {
        $rule = (array) $context->rules->get('vacation.supplement_sammaloneregeln');
        $percentBp = $context->vacation->supplementBp ?? (int) $rule['bp_of_monthly_salary_per_day'];
        $monthly = BigDecimal::of((int) $context->monthlySalaryOre)->multipliedBy($context->employmentRateBp)->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp);
        $perDay = $monthly->multipliedBy($percentBp)->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp);

        $draft->add(new PayslipLineResult('SEMESTERTILLAGG', __('Semestertillägg'), $perDay->multipliedBy($days)->toScale(0, RoundingMode::HalfUp)->toInt(), PayslipLineResult::TAX_TABLE, true, false,
            (string) $days, $perDay->toScale(0, RoundingMode::HalfUp)->toInt(),
            ruleVersionId: $context->rules->version('vacation.supplement_sammaloneregeln')->id,
            explanation: ['days' => $days, 'bp_of_monthly_salary_per_day' => $percentBp, 'monthly_salary_ore' => (string) $monthly->toScale(0, RoundingMode::HalfUp), ...self::agreement($context, $context->vacation?->supplementBp !== null)]));
    }

    /**
     * @param  list<AbsenceInput>  $paid
     */
    private function percentPay(PayrollContext $context, PayslipDraft $draft, array $paid, bool $monthly): void
    {
        $vacation = $context->vacation;

        if ($vacation === null || $vacation->paidDaysEarned < 1) {
            return;
        }

        $percentBp = $vacation->percentBp ?? (int) ((array) $context->rules->get('vacation.percent_rule'))['bp'];
        $perDay = BigDecimal::of($vacation->basisOre)->multipliedBy($percentBp)->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp)
            ->dividedBy($vacation->paidDaysEarned, Units::SCALE, RoundingMode::HalfUp);
        $minimumUsed = $vacation->minimumDailyOre !== null && $perDay->isLessThan($vacation->minimumDailyOre);

        if ($minimumUsed) {
            $perDay = BigDecimal::of((int) $vacation->minimumDailyOre);
        }

        $days = count($paid);

        if ($monthly) {
            // TODO(legal-verify): the salary for vacation days under procentregeln is deducted like other absence, by the hours of the schedule.
            $hours = $this->hours($paid);
            $hourValue = HourlyValue::hour($context);
            $draft->add(new PayslipLineResult('SEMESTERAVDRAG', __('Löneavdrag för semester'), -$hours->multipliedBy($hourValue)->toScale(0, RoundingMode::HalfUp)->toInt(), PayslipLineResult::TAX_TABLE, true, false,
                (string) $hours, $hourValue->toScale(0, RoundingMode::HalfUp)->toInt(), explanation: ['days' => $days, 'hours' => (string) $hours]));
        }

        $draft->add(new PayslipLineResult('SEMESTERLON', __('Semesterlön'), $perDay->multipliedBy($days)->toScale(0, RoundingMode::HalfUp)->toInt(), PayslipLineResult::TAX_TABLE, true, false,
            (string) $days, $perDay->toScale(0, RoundingMode::HalfUp)->toInt(),
            ruleVersionId: $context->rules->version('vacation.percent_rule')->id,
            explanation: ['days' => $days, 'basis_ore' => $vacation->basisOre, 'paid_days_earned' => $vacation->paidDaysEarned, 'bp' => $percentBp, ...($minimumUsed ? ['minimum_daily_ore' => $vacation->minimumDailyOre] : []), ...self::agreement($context, $vacation->percentBp !== null || $minimumUsed)]));
    }

    private function payout(PayrollContext $context, PayslipDraft $draft): void
    {
        $basis = array_sum(array_map(fn (PayslipLineResult $line): int => $line->amountOre, array_values(array_filter($draft->lines, fn (PayslipLineResult $line): bool => $line->vacationBasis))));

        if ($basis <= 0) {
            return;
        }

        $percentBp = $context->vacation->percentBp ?? (int) ((array) $context->rules->get('vacation.percent_rule'))['bp'];

        // TODO(legal-verify): semesterersättning paid with every salary; Skatteverket calls semesterersättning an
        // engångsbelopp but taxes regular payments by the table. The company chooses (setting vacation_payout_tax).
        $tax = $context->vacation?->payoutTax === 'ONE_OFF' ? PayslipLineResult::TAX_ONE_OFF : PayslipLineResult::TAX_TABLE;
        $draft->add(new PayslipLineResult('SEMESTERERSATTNING', __('Semesterersättning :percent %', ['percent' => str_replace('.', ',', (string) ($percentBp / Units::ORE))]),
            BigDecimal::of($basis)->multipliedBy($percentBp)->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt(), $tax, true, false,
            ruleVersionId: $context->rules->version('vacation.percent_rule')->id,
            explanation: ['basis_ore' => $basis, 'bp' => $percentBp, ...self::agreement($context, $context->vacation?->percentBp !== null)]));
    }

    /**
     * The agreement a vacation line follows, for its explanation.
     *
     * @return array<string, mixed>
     */
    private static function agreement(PayrollContext $context, bool $used): array
    {
        $agreement = $context->vacation->agreement ?? [];

        return $used && $agreement !== [] ? ['agreement' => $agreement] : [];
    }

    /**
     * The vacation days in the period that count as semesterdagar, on days
     * the employee was scheduled to work (VacationDays).
     *
     * @return list<AbsenceInput>
     */
    private function semesterdagar(PayrollContext $context): array
    {
        $byDate = [];

        foreach ($context->absences as $day) {
            if ($day->type === 'VACATION') {
                $byDate[$day->date->toDateString()] = $day;
            }
        }

        ksort($byDate);
        $scheduled = fn (CarbonImmutable $date): bool => BigDecimal::of($byDate[$date->toDateString()]->hours)->isPositive();
        $counted = [];

        foreach (VacationDays::spells(array_values(array_map(fn (AbsenceInput $day): CarbonImmutable => $day->date, $byDate))) as $spell) {
            foreach (VacationDays::counted($spell, $scheduled, $context->vacation->countMethod ?? VacationDays::NET) as $date) {
                $counted[] = $byDate[$date->toDateString()];
            }
        }

        return $counted;
    }

    /**
     * @param  list<AbsenceInput>  $days
     */
    private function hours(array $days): BigDecimal
    {
        return array_reduce($days, fn (BigDecimal $sum, AbsenceInput $day): BigDecimal => $sum->plus($day->hours), BigDecimal::zero());
    }
}
