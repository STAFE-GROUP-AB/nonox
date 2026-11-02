<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

use App\Domain\Calendar\SwedishHolidays;
use App\Modules\Agreements\Domain\TimeRounding;

/**
 * The payroll basis of an attested day (PLANNING_MODULE.md §5, §8): minutes
 * per pay code. Payroll owns pay: this says how much time of which kind,
 * never what it costs. Pure.
 *
 * - Paid by the hour: every actual minute is hourly pay (TIMLON), planned
 *   or not, plus OB. When the agreement gives hourly-paid staff overtime
 *   and mertid supplements, those are counted per calculation period
 *   (period()) on the agreement's supplement-only codes.
 * - Paid by the month: the salary already pays the planned time, so a day
 *   only adds OB. Overtime and mertid are counted per calculation period of
 *   attested days (period(), OvertimeTerms), on the pay codes the company
 *   chose. Shorter time is never deducted here; absence is registered in
 *   payroll, where sick pay and deductions are calculated.
 * - OB is split from the actual intervals by the agreement version's OB
 *   windows (ObSplitter), unless the employment has OB included in the
 *   salary. Without an agreement there are no OB windows and no OB; a day
 *   with time in typical OB hours then says so.
 */
final class PayrollBasis
{
    public const string HOURLY_CODE = 'TIMLON';

    public static function for(DayComparison $day, BasisTerms $terms): DayBasis
    {
        if ($terms->salaryForm === null) {
            return new DayBasis([], [], $day->actualMinutes() > 0
                ? [Deviation::blocking(Deviation::NO_EMPLOYMENT, __('Ingen anställning gäller dagen. Lägg in anställningen i lönen först.'))]
                : []);
        }

        $minutes = [];
        $deviations = [];
        $worked = $day->actualMinutes();
        $extra = $worked - $day->plannedMinutes();

        if ($terms->salaryForm === 'HOURLY') {
            $minutes[self::HOURLY_CODE] = $worked;

            if ($extra > 0) {
                // Arbetstidslagen gives no supplement of its own; an agreement that gives hourly-paid staff one (Gröna Riks § 12, Detaljhandeln § 9) is counted per period (AttestData::withWeeks).
                $deviations[] = Deviation::info(Deviation::EXTRA_TIME, $terms->agreement?->hasHourlySupplements() === true
                    ? __('Tid utöver schemat betalas som timlön. Avtalets övertids- och mertidstillägg räknas per beräkningsperiod och skickas med periodens sista dag.')
                    : __('Tid utöver schemat betalas som timlön. Övertids- eller mertidstillägg läggs in i lönekörningen om avtalet ger det.'));
            }
        } else {
            array_push($deviations, ...self::monthly($terms, $worked, $extra));
        }

        [$ob, $obDeviations] = self::ob($day, $terms);
        array_push($deviations, ...$obDeviations);

        foreach ($ob as $code => $value) {
            $minutes[$code] = ($minutes[$code] ?? 0) + $value;
        }

        $minutes = array_filter($minutes, fn (int $value): bool => $value > 0);
        ksort($minutes);

        return new DayBasis($minutes, $ob, $deviations);
    }

    /**
     * A monthly-paid day: the salary pays it, so nothing but OB. Time
     * beyond the plan is only noted; overtime and mertid are counted per
     * week (week()). Shorter time is never deducted here.
     *
     * @return list<Deviation>
     */
    private static function monthly(BasisTerms $terms, int $worked, int $extra): array
    {
        $deviations = [];

        if ($extra < 0 && ! $terms->absence) {
            $deviations[] = Deviation::info(Deviation::SHORT_TIME, __('Månadslön: kortare tid dras inte av här. Registrera frånvaro i lönen om den ska dras.'));
        }

        if ($extra > 0 && $worked > 0) {
            $deviations[] = Deviation::info(Deviation::EXTRA_TIME, __(':minutes min utöver schemat. Övertid och mertid räknas per vecka, mot veckoarbetstiden.', ['minutes' => $extra]));
        }

        return $deviations;
    }

    /**
     * Overtime and mertid of one calculation period of attested days
     * (OvertimeTerms; enligt AI-utredning 2026-11-02, juristgranskning
     * återstår):
     *
     * - PERIOD: the worked minutes, plus the planned minutes not worked on
     *   days with approved leave or comp leave (leave placed on ordinary
     *   time counts as worked, arbetstidslagen 7 § andra stycket and 10 §),
     *   plus what the agreement's rounding adds per day or occasion, against
     *   the period's contracted minutes.
     * - DAILY: the sum of each day's rounded time beyond the day's planned
     *   time (Visita–Unionen § 11.1, Handelns tjänstemannaavtal § 4.2).
     *
     * Rounding is never applied to the period total.
     *
     * TODO(legal-verify): whether sick days count as "ledighet" under 7 §
     * andra stycket (NONOX counts every approved absence on a scheduled day).
     *
     * @param  list<DayComparison>  $days
     * @param  array<string, true>  $leaveDays  dates (Y-m-d) with approved absence or comp leave
     */
    public static function period(array $days, array $leaveDays, OvertimeTerms $terms, int $contractedMinutes, int $fullTimeMinutes, ?string $overtimeCode, ?string $extraTimeCode, bool $overtimeIncluded, int $weeks = 1): WeekBasis
    {
        $worked = 0;
        $credit = 0;
        $adjustment = 0;
        $daily = 0;
        $ignored = 0;

        foreach ($days as $day) {
            $worked += $day->actualMinutes();

            if (isset($leaveDays[$day->date->toDateString()])) {
                $credit += max(0, $day->plannedMinutes() - $day->actualMinutes());
            }

            $extra = $terms->day($day);
            $adjustment += $extra['counted'] - $extra['raw'];
            $daily += $extra['counted'];
            $ignored += $extra['ignored'];
        }

        $measured = $terms->method === OvertimeTerms::DAILY
            ? $contractedMinutes + $daily
            : $worked + $credit + $adjustment;
        $week = self::week($measured, $contractedMinutes, $fullTimeMinutes, $overtimeCode, $extraTimeCode, $overtimeIncluded, $weeks);
        $notes = [];

        if ($terms->method === OvertimeTerms::DAILY && $week->hasExtraTime()) {
            $notes[] = Deviation::info(Deviation::EXTRA_TIME, __('Övertid räknas per dag mot dagens ordinarie arbetstid enligt avtalet (:source), inte netto över perioden.', ['source' => $terms->methodSource]));
        }

        if ($terms->method === OvertimeTerms::PERIOD && $credit > 0 && $week->hasExtraTime()) {
            $notes[] = Deviation::info(Deviation::EXTRA_TIME, __(':minutes min ledighet på schemalagd tid räknas som arbetad tid när övertid och mertid räknas (arbetstidslagen 7 § andra stycket).', ['minutes' => $credit]));
        }

        if ($ignored > 0) {
            $notes[] = Deviation::info(Deviation::EXTRA_TIME, __(':minutes min förlängning räknas inte som övertid: högst :threshold min per tillfälle (:source).', ['minutes' => $ignored, 'threshold' => $terms->thresholdMinutes, 'source' => $terms->rounding['source'] ?? '']));
        }

        if ($terms->rounding !== null && $adjustment !== 0 && $week->hasExtraTime()) {
            $notes[] = Deviation::info(Deviation::EXTRA_TIME, $terms->per === OvertimeTerms::PER_OCCASION
                ? __('Övertiden är avrundad per tillfälle enligt avtalet (:source).', ['source' => $terms->rounding['source']])
                : __('Övertiden är avrundad per dag enligt avtalet (:source).', ['source' => $terms->rounding['source']]));
        }

        return $notes === [] ? $week : new WeekBasis($week->overtimeMinutes, $week->extraTimeMinutes, $week->minutes, [...$week->deviations, ...$notes]);
    }

    /**
     * Overtime and mertid from the measured minutes of one calculation
     * period: the minutes beyond the contracted time. For part time, the
     * minutes up to the ordinary full-time period are mertid and the rest
     * overtime; for full time all of it is overtime. Each goes on the pay
     * code given; without one it is shown for the manager to enter in the
     * payroll run. Shorter periods are never deducted.
     *
     * TODO(legal-verify): public holidays without a planned shift, the
     * agreements' overtime kinds (adjoining or not, first hours or later)
     * and the split between mertid and overtime under a daily method.
     */
    public static function week(int $workedMinutes, int $contractedMinutes, int $fullTimeMinutes, ?string $overtimeCode, ?string $extraTimeCode, bool $overtimeIncluded, int $weeks = 1): WeekBasis
    {
        $excess = $workedMinutes - $contractedMinutes;

        if ($excess <= 0 || $contractedMinutes <= 0) {
            return new WeekBasis(0, 0, [], []);
        }

        $extra = max(0, min($excess, $fullTimeMinutes - $contractedMinutes));
        $overtime = $excess - $extra;
        $period = $weeks > 1 ? __('Perioden (:weeks veckor)', ['weeks' => $weeks]) : __('Veckan');

        if ($overtimeIncluded) {
            return new WeekBasis($overtime, $extra, [], [Deviation::info(Deviation::EXTRA_TIME, __(':period: :minutes min utöver veckoarbetstiden. Övertid ingår i lönen enligt anställningen.', ['period' => $period, 'minutes' => $excess]))]);
        }

        $minutes = [];
        $deviations = [];

        foreach ([[$extra, $extraTimeCode, 'mertid'], [$overtime, $overtimeCode, 'övertid']] as [$value, $code, $kind]) {
            if ($value <= 0) {
                continue;
            }

            if ($code === null) {
                $deviations[] = new Deviation(Deviation::EXTRA_TIME, $kind === 'mertid'
                    ? __(':period: :minutes min mertid utöver veckoarbetstiden. Ingen löneart för mertid är vald under Planering → Inställningar; ta den som komptid eller lägg in tiden i lönekörningen.', ['period' => $period, 'minutes' => $value])
                    : __(':period: :minutes min övertid utöver veckoarbetstiden. Ingen löneart för övertid är vald under Planering → Inställningar; ta den som komptid eller lägg in tiden i lönekörningen.', ['period' => $period, 'minutes' => $value]));

                continue;
            }

            $minutes[$code] = ($minutes[$code] ?? 0) + $value;
            $deviations[] = new Deviation(Deviation::EXTRA_TIME, $kind === 'mertid'
                ? __(':period: :minutes min mertid skickas med den här dagen (:code).', ['period' => $period, 'minutes' => $value, 'code' => $code])
                : __(':period: :minutes min övertid skickas med den här dagen (:code).', ['period' => $period, 'minutes' => $value, 'code' => $code]));
        }

        ksort($minutes);

        return new WeekBasis($overtime, $extra, $minutes, $deviations);
    }

    /**
     * OB minutes per code over the actual intervals.
     *
     * @return array{array<string, int>, list<Deviation>}
     */
    private static function ob(DayComparison $day, BasisTerms $terms): array
    {
        if ($terms->agreementUnverified) {
            return [[], [Deviation::blocking(Deviation::AGREEMENT_UNVERIFIED, __('Anställningen följer ett kollektivavtal utan kontrollerad version för dagen, så OB kan inte räknas. Kontrollera versionen under Lön → Avtal.'))]];
        }

        if ($terms->agreement === null || $terms->obIncluded) {
            if (! self::inTypicalObHours($day)) {
                return [[], []];
            }

            return [[], [Deviation::info(Deviation::NO_OB, $terms->obIncluded
                ? __('Ingen OB räknas: OB ingår i lönen enligt anställningen.')
                : __('Ingen OB räknas: inget kollektivavtal i Avtal. Lägg in OB i lönekörningen om ni ger det.'))]];
        }

        $ob = [];
        $deviations = [];
        $withBreak = false;
        $rounding = $terms->agreement->rounding('OB');
        $rounded = false;

        foreach ($day->actual as $interval) {
            if (! $interval->isComplete() || $interval->start === null || $interval->end === null) {
                continue;
            }

            $split = ObSplitter::split($terms->agreement, $interval->start, $interval->end, $terms->hourlyRateOre, $terms->monthlySalaryOre);

            foreach ($split->minutes as $code => $value) {
                $paid = TimeRounding::round($value, $rounding);
                $rounded = $rounded || $paid !== $value;
                $ob[$code] = ($ob[$code] ?? 0) + $paid;
            }

            foreach ($split->notes as $note) {
                $deviations[] = Deviation::info(Deviation::OB_NOTE, $note);
            }

            $withBreak = $withBreak || ($interval->breakMinutes > 0 && $split->minutes !== []);
        }

        if ($withBreak) {
            // The ledger has no break times, so OB is counted over the whole interval, break included.
            $deviations[] = Deviation::info(Deviation::OB_WITH_BREAK, __('OB räknas på hela passet, rasten inräknad, eftersom rastens placering inte är känd. Låg rasten på OB-tid: dela upp tiden under Ändra.'));
        }

        if ($rounded && $rounding !== null) {
            $deviations[] = Deviation::info(Deviation::OB_NOTE, $rounding['method'] === 'UP_PER_STARTED_UNIT'
                ? __('OB är avrundad enligt avtalet: varje påbörjad period om :unit min räknas hel, per pass (:source).', ['unit' => $rounding['unit_minutes'], 'source' => $rounding['source']])
                : __('OB är avrundad enligt avtalet: bara hela perioder om :unit min räknas, per pass (:source).', ['unit' => $rounding['unit_minutes'], 'source' => $rounding['source']]));
        }

        ksort($ob);

        return [$ob, $deviations];
    }

    /**
     * Whether worked time falls where OB is commonly paid: evenings and
     * nights (19:00–06:00), weekends and public holidays. Only a hint that
     * no OB is counted; it decides no pay.
     */
    private static function inTypicalObHours(DayComparison $day): bool
    {
        foreach ($day->actual as $interval) {
            if (! $interval->isComplete() || $interval->start === null || $interval->end === null) {
                continue;
            }

            for ($minute = $interval->start; $minute < $interval->end; $minute = $minute->addMinutes(30)) {
                $local = $minute->setTimezone('Europe/Stockholm');

                if ($local->hour >= 19 || $local->hour < 6 || $local->isWeekend() || SwedishHolidays::isPublicHoliday($local->startOfDay())) {
                    return true;
                }
            }

            $last = $interval->end->setTimezone('Europe/Stockholm')->subMinute();

            if ($last->hour >= 19 || $last->hour < 6) {
                return true;
            }
        }

        return false;
    }
}
