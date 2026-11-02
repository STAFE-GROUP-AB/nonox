<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

use App\Domain\Calendar\SwedishHolidays;

/**
 * The payroll basis of an attested day (PLANNING_MODULE.md §5, §8): minutes
 * per pay code. Payroll owns pay: this says how much time of which kind,
 * never what it costs. Pure.
 *
 * - Paid by the hour: every actual minute is hourly pay (TIMLON), planned
 *   or not, plus OB.
 * - Paid by the month: the salary already pays the planned time, so a day
 *   only adds OB. Overtime and mertid are counted per calendar week of
 *   attested days against the contracted weekly time (week()), on the pay
 *   codes the company chose. Shorter time is never deducted here; absence
 *   is registered in payroll, where sick pay and deductions are calculated.
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
                // TODO(legal-verify): when time beyond the schedule gives an hourly-paid employee an overtime or mertid supplement under arbetstidslagen and the agreement.
                $deviations[] = Deviation::info(Deviation::EXTRA_TIME, __('Tid utöver schemat betalas som timlön. Övertids- eller mertidstillägg läggs in i lönekörningen om avtalet ger det.'));
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
     * Overtime and mertid of a monthly-paid employee for one calendar week
     * (Monday to Sunday) of attested days: the worked minutes beyond the
     * contracted weekly time. For part time, the minutes up to the ordinary
     * full-time week are mertid and the rest overtime; for full time all of
     * it is overtime. Each goes on the pay code the company chose; without
     * one it is shown for the manager to enter in the payroll run. Shorter
     * weeks are never deducted.
     *
     * TODO(legal-verify): arbetstidslagen 7 § and 10 § and the agreement may
     * use a longer reference period than the calendar week (an average over
     * several weeks, a schedule period), count absence and public holidays
     * as worked or reduce the weekly time for them, and set their own
     * overtime kinds (in connection or not, first hours or later). None of
     * that is applied here.
     */
    public static function week(int $workedMinutes, int $contractedMinutes, int $fullTimeMinutes, ?string $overtimeCode, ?string $extraTimeCode, bool $overtimeIncluded): WeekBasis
    {
        $excess = $workedMinutes - $contractedMinutes;

        if ($excess <= 0 || $contractedMinutes <= 0) {
            return new WeekBasis(0, 0, [], []);
        }

        $extra = max(0, min($excess, $fullTimeMinutes - $contractedMinutes));
        $overtime = $excess - $extra;

        if ($overtimeIncluded) {
            return new WeekBasis($overtime, $extra, [], [Deviation::info(Deviation::EXTRA_TIME, __('Veckan: :minutes min utöver veckoarbetstiden. Övertid ingår i lönen enligt anställningen.', ['minutes' => $excess]))]);
        }

        $minutes = [];
        $deviations = [];

        foreach ([[$extra, $extraTimeCode, 'mertid'], [$overtime, $overtimeCode, 'övertid']] as [$value, $code, $kind]) {
            if ($value <= 0) {
                continue;
            }

            if ($code === null) {
                $deviations[] = new Deviation(Deviation::EXTRA_TIME, $kind === 'mertid'
                    ? __('Veckan: :minutes min mertid utöver veckoarbetstiden. Ingen löneart för mertid är vald under Planering → Inställningar; lägg in tiden i lönekörningen.', ['minutes' => $value])
                    : __('Veckan: :minutes min övertid utöver veckoarbetstiden. Ingen löneart för övertid är vald under Planering → Inställningar; lägg in tiden i lönekörningen.', ['minutes' => $value]));

                continue;
            }

            $minutes[$code] = ($minutes[$code] ?? 0) + $value;
            $deviations[] = new Deviation(Deviation::EXTRA_TIME, $kind === 'mertid'
                ? __('Veckan: :minutes min mertid skickas med den här dagen (:code).', ['minutes' => $value, 'code' => $code])
                : __('Veckan: :minutes min övertid skickas med den här dagen (:code).', ['minutes' => $value, 'code' => $code]));
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

        foreach ($day->actual as $interval) {
            if (! $interval->isComplete() || $interval->start === null || $interval->end === null) {
                continue;
            }

            $split = ObSplitter::split($terms->agreement, $interval->start, $interval->end, $terms->hourlyRateOre, $terms->monthlySalaryOre);

            foreach ($split->minutes as $code => $value) {
                $ob[$code] = ($ob[$code] ?? 0) + $value;
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
