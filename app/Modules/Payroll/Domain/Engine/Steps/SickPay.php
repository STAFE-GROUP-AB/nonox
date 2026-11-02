<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine\Steps;

use App\Modules\Payroll\Domain\Engine\AbsenceInput;
use App\Modules\Payroll\Domain\Engine\HourlyValue;
use App\Modules\Payroll\Domain\Engine\KarensTerms;
use App\Modules\Payroll\Domain\Engine\LongSickTerms;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\SickHistory;
use App\Modules\Payroll\Domain\Engine\SickHourValue;
use App\Modules\Payroll\Domain\Engine\Step;
use App\Modules\Payroll\Domain\Units;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Step 3: sick pay under sjuklönelagen (rules sick_pay.*). A monthly salary
 * loses the pay for the sick hours (sjukavdrag); for the days of the
 * employer's period the employee gets the sick-pay percentage of that pay.
 * After the employer's period there is no sick pay and the payslip flags
 * Försäkringskassan.
 *
 * Sick days are grouped into sjuklöneperioder: a spell starting within the
 * reinfection window after the previous one ended continues it, also
 * across runs (SickHistory). Each new period is due one karensavdrag (the
 * karensavdrag percentage of an average week's sick pay, 6 § andra
 * stycket) unless the employee has had a karensavdrag on the rule's number
 * of occasions in the twelve months back from the period's first day
 * (6 § tredje stycket). Following prop. 2017/18:96 s. 68–69 the
 * karensavdrag is capped per period at that period's sick pay; what this
 * payslip's sick pay in the period cannot absorb is carried to the
 * period's later sick-pay days, in the next run and after reinfection
 * (7 § tredje stycket), never taken from pay for work or from another
 * period. What each period got is returned in PayslipDraft::$sickPeriods.
 *
 * With SickPayTerms from another module (A8): the OB the schedule would
 * have paid on the sick days of the employer's period is paid with the
 * sick-pay percentage; the average week behind the karensavdrag includes
 * the average weekly OB and, for irregular hours without weekly hours, the
 * average weekly hours; a collective agreement may set its own hour (or
 * day) value of sick pay (SickHourValue) and count the karensavdrag in
 * time (KarensTerms), as sjuklönelagen 2 § andra stycket allows; and an
 * agreement's deduction per calendar day replaces the hourly deduction from
 * its first to its last day, at most its ceiling of days in twelve months.
 *
 * Enligt AI-utredning 2026-11-02, juristgranskning återstår.
 * TODO(legal-verify): the carry of the karensavdrag and the occasion count
 * (sjuklönelagen 6–7 §§, prop. 2017/18:96 s. 68–69); part-time sick leave
 * from day 15 under an agreement (the whole deduction per calendar day is
 * made; a note says so).
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
        $agreementValue = $context->salaryForm === 'MONTHLY' ? $terms?->hourValue : null;
        $hourValue = $this->hourValue($context, $agreementValue);
        $longSick = $context->salaryForm === 'MONTHLY' ? $terms?->longSick : null;
        $history = SickHistory::from($context->sickHistory, $window);
        $warnings = $terms === null ? [] : $terms->warnings;

        $lost = BigDecimal::zero();
        $paidHours = BigDecimal::zero();
        $unpaidHours = BigDecimal::zero();
        $paidDeduction = BigDecimal::zero();
        $supplements = 0;
        $supplementDays = [];
        $longDays = 0;
        $longDeduction = 0;
        $longDates = $history->longSickDates;
        $capReached = false;
        $partialLong = false;
        $karensAmount = null;
        $occasionsInRun = [];
        /** @var array<string, array{start: CarbonImmutable, last: CarbonImmutable, days: int, full: int, remaining: int, deducted: int, paid: BigDecimal, supplements: int, long: list<string>, carried: bool, blocked: bool}> $periods */
        $periods = [];
        $previous = $history->last;
        $previousFromHistory = true;

        foreach ($this->spells($days) as $spell) {
            $start = $spell[0]->date;
            $end = $spell[count($spell) - 1]->date;
            $continues = $previous !== null && $start->greaterThan($previous['end']) && (int) $previous['end']->diffInDays($start) <= $window;
            $periodStart = $continues ? $previous['start'] : $start;
            $offset = $continues ? $previous['days'] : 0;
            $key = $periodStart->toDateString();

            if (! isset($periods[$key])) {
                // A period from before this payslip: the rest of its karensavdrag, if an approved payslip recorded it.
                if ($continues && $previousFromHistory) {
                    if (! $history->recorded($periodStart) && $history->legacyCount === null) {
                        $this->warn($warnings, __('Sjukperioden från :date finns inte i någon attesterad lönekörning. Karensavdraget för den räknas som redan gjort.', ['date' => $key]));
                    }

                    $periods[$key] = $this->period($periodStart, $history->karensFull($periodStart), $history->karensRemaining($periodStart), true, false);
                } else {
                    $this->settle($periods, $percentBp, $occasionsInRun);
                    $allowed = $history->occasionsBefore($periodStart, $occasionsInRun) < $maxKarens;
                    $karensAmount ??= $this->karensAmount($context, $hourValue, $agreementValue, $percentBp, $karensBp, $warnings);
                    $full = $allowed ? $karensAmount->toScale(0, RoundingMode::HalfUp)->toInt() : 0;
                    $periods[$key] = $this->period($periodStart, $full, $full, false, ! $allowed);
                }
            }

            foreach ($spell as $day) {
                $hours = BigDecimal::of($day->hours);
                $number = $offset + (int) $start->diffInDays($day->date) + 1;
                $periods[$key]['last'] = $day->date;
                $periods[$key]['days'] = $number;

                if ($longSick !== null && $number >= $longSick->fromDay && $number <= $longSick->toDay) {
                    if ($longSick->maxDaysIn12Months === null || $this->daysIn($longDates, $periodStart->subYear(), $day->date) < $longSick->maxDaysIn12Months) {
                        $longDays++;
                        $longDeduction += $this->calendarDayDeduction($context, $longSick);
                        $longDates[] = $day->date->toDateString();
                        $periods[$key]['long'][] = $day->date->toDateString();
                        $partialLong = $partialLong || $day->extentBp < Units::BASIS_POINTS;

                        continue;
                    }

                    $capReached = true;
                }

                $deduction = $this->dayDeduction($context, $agreementValue, $hourValue, $day);
                $lost = $lost->plus($deduction);

                if ($number <= $employerDays) {
                    $paidHours = $paidHours->plus($hours);
                    $paidDeduction = $paidDeduction->plus($deduction);
                    $periods[$key]['paid'] = $periods[$key]['paid']->plus($deduction);

                    if ($day->lostSupplementsOre > 0) {
                        $supplements += $day->lostSupplementsOre;
                        $periods[$key]['supplements'] += $day->lostSupplementsOre;
                        $supplementDays[$day->date->toDateString()] = $day->supplements;
                    }

                    continue;
                }

                $unpaidHours = $unpaidHours->plus($hours);
            }

            $previous = ['start' => $periodStart, 'end' => $end, 'days' => $offset + (int) $start->diffInDays($end) + 1];
            $previousFromHistory = false;
        }

        $this->settle($periods, $percentBp, $occasionsInRun);

        $sickPay = $paidDeduction->multipliedBy($percentBp)->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt();
        $supplementSickPay = BigDecimal::of($supplements)->multipliedBy($percentBp)->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt();

        if ($context->salaryForm === 'MONTHLY') {
            $draft->add(new PayslipLineResult('SJUKAVDRAG', __('Sjukavdrag'), -$lost->toScale(0, RoundingMode::HalfUp)->toInt(), PayslipLineResult::TAX_TABLE, true, true,
                explanation: array_filter([
                    'hours' => (string) $paidHours->plus($unpaidHours),
                    'hour_value_ore' => (string) $hourValue->toScale(Units::SHOWN_DECIMALS, RoundingMode::HalfUp),
                    'hour_value_method' => $agreementValue?->method,
                    'day_bp' => $agreementValue?->dayBp,
                    'month_working_hours' => $agreementValue?->monthWorkingHours,
                    'source' => $agreementValue?->source,
                ], fn (mixed $value): bool => $value !== null)));
        }

        if ($longDeduction > 0 && $longSick !== null) {
            $draft->add(new PayslipLineResult('SJUKAVDRAG', __('Sjukavdrag dag :from–:to enligt avtal, :days kalenderdagar', ['from' => $longSick->fromDay, 'to' => $longSick->toDay, 'days' => $longDays]), -$longDeduction, PayslipLineResult::TAX_TABLE, true, true,
                explanation: array_filter([
                    'days' => $longDays,
                    'below_bp' => $longSick->belowBp,
                    'above_bp' => $longSick->aboveBp,
                    'threshold_yearly_ore' => $longSick->thresholdYearlyOre,
                    'max_days_12_months' => $longSick->maxDaysIn12Months,
                    'extra_monthly_ore' => $longSick->extraMonthlyOre,
                    'cap_extra_monthly_ore' => $longSick->capExtraMonthlyOre,
                    ...$longSick->basisExplanation,
                    'source' => $longSick->source,
                ], fn (mixed $value): bool => $value !== null)));
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

        foreach ($periods as $key => $period) {
            if ($period['deducted'] > 0) {
                $draft->add(new PayslipLineResult('KARENSAVDRAG', __('Karensavdrag'), -$period['deducted'], PayslipLineResult::TAX_TABLE, true, true,
                    ruleVersionId: $rules->version('sick_pay.karensavdrag_percent_of_weekly')->id,
                    explanation: array_filter([
                        'period_start' => $key,
                        'karens_full_ore' => $period['full'],
                        'karens_left_ore' => $period['remaining'],
                        'carried' => $period['carried'] ? true : null,
                        'rate_bp' => $terms?->karens === null ? $karensBp : $terms->karens->percentOfWeeklyHoursBp,
                        'karens_hours' => $terms?->karens === null ? null : (string) $this->karensHours($terms->karens),
                        'weekly_hours_source' => $terms?->karens?->weeklyHoursSource,
                        'average_weekly_supplements_ore' => $terms?->averageWeeklySupplementsOre,
                        'average_weekly_hours' => $terms?->averageWeeklyHours,
                        'average_weekly_hours_source' => $terms?->explanation['average_weekly_hours_source'] ?? null,
                        'source' => $terms?->karens?->source,
                    ], fn (mixed $value): bool => $value !== null)));
            }

            if ($period['remaining'] > 0) {
                $amount = number_format($period['remaining'] / Units::ORE, Units::SHOWN_DECIMALS, ',', ' ');
                // The rest can still be taken if the period goes on, or a reinfection continues it, after this payslip's month.
                $canContinue = $period['days'] < $employerDays && (int) $period['last']->diffInDays($context->periodEnd->addDay()) <= $window;
                $this->warn($warnings, $canContinue
                    ? __('Hela karensavdraget för sjukperioden från :date rymdes inte i sjuklönen. Resten, :amount kr, dras från sjuklönen om sjukperioden fortsätter i nästa lönekörning.', ['date' => $key, 'amount' => $amount])
                    : __('Hela karensavdraget för sjukperioden från :date rymdes inte i sjuklönen. Resten, :amount kr, dras inte eftersom sjukperioden är slut.', ['date' => $key, 'amount' => $amount]));
            }

            if ($period['blocked']) {
                $this->warn($warnings, __('Inget karensavdrag för sjukperioden från :date: den anställda har redan haft karensavdrag :max gånger de senaste tolv månaderna.', ['date' => $key, 'max' => $maxKarens]));
            }

            $draft->sickPeriods[] = [
                'period_start' => $key,
                'last_day' => $period['last']->toDateString(),
                'day_count' => $period['days'],
                'karens_full_ore' => $period['full'],
                'karens_deducted_ore' => $period['deducted'],
                'long_sick_dates' => $period['long'],
            ];
        }

        if ($longDays > 0 && $longSick !== null) {
            array_push($warnings, ...$longSick->warnings);
        }

        if ($capReached && $longSick !== null) {
            $this->warn($warnings, __('Taket på :max dagar med sjuklön från dag :from under tolv månader enligt avtalet är nått. Resten av sjukdagarna dras som vanligt sjukavdrag.', ['max' => $longSick->maxDaysIn12Months, 'from' => $longSick->fromDay]));
        }

        if ($partialLong && $longSick !== null) {
            // TODO(legal-verify): the agreements read say nothing about part-time sick leave from day 15.
            $this->warn($warnings, __('Deltidssjukskrivning från dag :from: hela avtalets avdrag per kalenderdag är gjort. Avtalet säger inget om deltid, så kontrollera avdraget.', ['from' => $longSick->fromDay]));
        }

        if ($unpaidHours->isPositive() || $longDays > 0) {
            $this->warn($warnings, __('Sjukperioden är längre än arbetsgivarperioden. Anmäl sjukfallet till Försäkringskassan.'));
        }

        array_push($draft->warnings, ...array_values(array_unique($warnings)));
    }

    /**
     * @param  list<string>  $warnings
     *
     * @param-out list<string>  $warnings
     */
    private function warn(array &$warnings, string $message): void
    {
        $warnings[] = $message;
    }

    /**
     * @return array{start: CarbonImmutable, last: CarbonImmutable, days: int, full: int, remaining: int, deducted: int, paid: BigDecimal, supplements: int, long: list<string>, carried: bool, blocked: bool}
     */
    private function period(CarbonImmutable $start, int $full, int $remaining, bool $carried, bool $blocked): array
    {
        return ['start' => $start, 'last' => $start, 'days' => 0, 'full' => $full, 'remaining' => $remaining, 'deducted' => 0, 'paid' => BigDecimal::zero(), 'supplements' => 0, 'long' => [], 'carried' => $carried && $remaining > 0, 'blocked' => $blocked];
    }

    /**
     * Takes each period's karensavdrag from its own sick pay, never more,
     * and keeps the rest to carry. A period counts as an occasion once
     * something has been deducted.
     *
     * @param  array<string, array{start: CarbonImmutable, last: CarbonImmutable, days: int, full: int, remaining: int, deducted: int, paid: BigDecimal, supplements: int, long: list<string>, carried: bool, blocked: bool}>  $periods
     * @param  list<string>  $occasions  first days of the periods in this payslip that got a karensavdrag
     */
    private function settle(array &$periods, int $percentBp, array &$occasions): void
    {
        foreach ($periods as $key => $period) {
            if ($period['deducted'] > 0 || $period['remaining'] === 0) {
                continue;
            }

            $sickPay = $period['paid']->multipliedBy($percentBp)->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt()
                + BigDecimal::of($period['supplements'])->multipliedBy($percentBp)->dividedBy(Units::BASIS_POINTS, 0, RoundingMode::HalfUp)->toInt();
            $deducted = min($period['remaining'], $sickPay);
            $periods[$key]['deducted'] = $deducted;
            $periods[$key]['remaining'] = $period['remaining'] - $deducted;

            if ($deducted > 0) {
                $occasions[] = $key;
            }
        }
    }

    /**
     * The karensavdrag of a new period. By the law (6 § andra stycket): the
     * karensavdrag percentage of the sick pay for an average week, the week
     * including the average weekly OB. By an agreement that counts it in
     * time: the agreement's share of the weekly hours at the sick pay of an
     * hour, plus the same share of the average weekly OB.
     *
     * @param  list<string>  $warnings
     */
    private function karensAmount(PayrollContext $context, BigDecimal $hourValue, ?SickHourValue $agreementValue, int $percentBp, int $karensBp, array &$warnings): BigDecimal
    {
        $terms = $context->sickPayTerms;
        $weeklyOb = $terms === null ? 0 : ($terms->averageWeeklySupplementsOre ?? 0);
        $karens = $terms?->karens;

        if ($karens === null) {
            $week = HourlyValue::week($context)->plus($weeklyOb);

            return $week->multipliedBy($percentBp)->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp)
                ->multipliedBy($karensBp)->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp);
        }

        $value = $hourValue;

        if ($agreementValue?->method === SickHourValue::DAY_PERCENT) {
            $value = $this->monthly($context)->dividedBy((string) $agreementValue->hourDivisor, Units::SCALE, RoundingMode::HalfUp);
            // TODO(legal-verify): Detaljhandelsavtalet § 13.1 deducts 4,6 % per full day; the karens time is valued per hour here.
            $this->warn($warnings, __('Karensavdraget räknas som karenstimmar à 1/:divisor av månadslönen. Avtalets avdrag per hel dag används inte för karenstiden, så kontrollera avdraget.', ['divisor' => $agreementValue->hourDivisor]));
        }

        return $this->karensHours($karens)->multipliedBy($value)->plus(BigDecimal::of($weeklyOb)->multipliedBy($karens->percentOfWeeklyHoursBp)->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp))
            ->multipliedBy($percentBp)->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp);
    }

    private function karensHours(KarensTerms $karens): BigDecimal
    {
        return BigDecimal::of($karens->weeklyHours)->multipliedBy($karens->percentOfWeeklyHoursBp)->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp);
    }

    /**
     * The value of a sick hour: the hourly rate, the agreement's value or
     * HourlyValue. For DAY_PERCENT the hour value of a part day.
     */
    private function hourValue(PayrollContext $context, ?SickHourValue $value): BigDecimal
    {
        if ($value === null) {
            return HourlyValue::hour($context);
        }

        return match ($value->method) {
            SickHourValue::MONTHLY_PER_WEEK => $this->monthly($context)->multipliedBy((string) $value->monthsPerYear)
                ->dividedBy(BigDecimal::of((string) $value->weeksPerYear)->multipliedBy($this->weeklyHours($context)), Units::SCALE, RoundingMode::HalfUp),
            SickHourValue::MONTH_WORKING_HOURS => $value->monthWorkingHours === null || BigDecimal::of($value->monthWorkingHours)->isZero()
                ? throw new PayrollBlockedException(__('Avtalet räknar sjuklönen per timme som månadslönen delad med månadens arbetstimmar, men arbetstimmarna i månaden är okända. Lägg in ett schema på anställningen eller publicera passen i Planering.'))
                : $this->monthly($context)->dividedBy($value->monthWorkingHours, Units::SCALE, RoundingMode::HalfUp),
            SickHourValue::DAY_PERCENT => $this->monthly($context)->dividedBy((string) $value->hourDivisor, Units::SCALE, RoundingMode::HalfUp),
            default => throw new PayrollBlockedException(__('Avtalets sätt att räkna sjuklön per timme (:method) känns inte igen.', ['method' => $value->method])),
        };
    }

    /**
     * The sick deduction for one day: hours × hour value, or by
     * DAY_PERCENT the agreement's share of the monthly salary for a full
     * day (all the day's scheduled hours) and the hour value for a part day.
     */
    private function dayDeduction(PayrollContext $context, ?SickHourValue $value, BigDecimal $hourValue, AbsenceInput $day): BigDecimal
    {
        $hours = BigDecimal::of($day->hours);

        if ($value?->method !== SickHourValue::DAY_PERCENT || ! $hours->isPositive()) {
            return $hours->multipliedBy($hourValue);
        }

        $scheduled = isset($day->supplements['scheduled_minutes'])
            ? BigDecimal::of((int) $day->supplements['scheduled_minutes'])->dividedBy(Units::MINUTES_PER_HOUR, Units::SHOWN_DECIMALS, RoundingMode::HalfUp)
            : BigDecimal::of($context->weekPattern[$day->date->dayOfWeekIso - 1] ?? '0');
        $full = $scheduled->isPositive() && $hours->isGreaterThanOrEqualTo($scheduled);

        return $full
            ? $this->monthly($context)->multipliedBy((int) $value->dayBp)->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp)
            : $hours->multipliedBy($hourValue);
    }

    private function monthly(PayrollContext $context): BigDecimal
    {
        return BigDecimal::of((int) $context->monthlySalaryOre)->multipliedBy($context->employmentRateBp)->dividedBy(Units::BASIS_POINTS, Units::SCALE, RoundingMode::HalfUp);
    }

    private function weeklyHours(PayrollContext $context): BigDecimal
    {
        if ($context->weeklyHours === null || BigDecimal::of($context->weeklyHours)->isZero()) {
            throw new PayrollBlockedException(__('Veckoarbetstid saknas på anställningen. Den behövs för frånvaro och sjuklön.'));
        }

        return BigDecimal::of($context->weeklyHours);
    }

    /**
     * Days in a list on or after $from and before $before.
     *
     * @param  list<string>  $dates
     */
    private function daysIn(array $dates, CarbonImmutable $from, CarbonImmutable $before): int
    {
        $from = $from->toDateString();
        $before = $before->toDateString();

        return count(array_unique(array_filter($dates, fn (string $date): bool => $date >= $from && $date < $before)));
    }

    /**
     * The agreement's deduction for one calendar day of long sickness: the
     * share of the yearly salary below and above the threshold, per day,
     * never more than a day's fixed salary. The yearly salary includes what
     * the agreement counts in "månadslön" (LongSickTerms::extraMonthlyOre).
     */
    private function calendarDayDeduction(PayrollContext $context, LongSickTerms $terms): int
    {
        $monthly = $this->monthly($context);
        $yearly = $monthly->plus($terms->extraMonthlyOre)->multipliedBy(Units::MONTHS_PER_YEAR);
        $below = BigDecimal::min($yearly, BigDecimal::of($terms->thresholdYearlyOre));
        $above = BigDecimal::max(BigDecimal::zero(), $yearly->minus($terms->thresholdYearlyOre));
        $deduction = $below->multipliedBy($terms->belowBp)->plus($above->multipliedBy($terms->aboveBp))
            ->dividedBy(Units::BASIS_POINTS * $terms->daysPerYear, Units::SCALE, RoundingMode::HalfUp);
        $cap = $monthly->plus($terms->capExtraMonthlyOre)->multipliedBy(Units::MONTHS_PER_YEAR)->dividedBy($terms->daysPerYear, Units::SCALE, RoundingMode::HalfUp);

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
