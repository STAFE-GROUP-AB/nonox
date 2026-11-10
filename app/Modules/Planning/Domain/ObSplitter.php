<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

use App\Domain\Calendar\SwedishHolidays;
use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Agreements\Domain\DayCalendar;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Splits a worked interval into minutes per OB code from one agreement
 * version's ob_windows and day_types. Pure: no database.
 *
 * A window has either days (MON..SUN) with from and to ('24:00' = the end of
 * the day) or to_next_day, or a day_type whose spans come from the
 * agreement's day_types. A window belongs to the day it starts on. Every
 * boundary is a wall-clock time in Europe/Stockholm; minutes are real elapsed
 * minutes, so a night across a DST change is one hour shorter or longer.
 *
 * Per code the windows are a union (a minute counts once). Across codes the
 * minutes are cumulative, as the agreement data describes them, unless the
 * windows carry "overlap": "HIGHEST_ONLY": where windows of different codes
 * marked so overlap, only the code with the highest hourly value is kept.
 */
final class ObSplitter
{
    public const string TIMEZONE = 'Europe/Stockholm';

    /** Days to look back for windows that start before the interval (an until NEXT_WEEKDAY span can cover a long weekend). */
    private const int LOOKBACK_DAYS = 7;

    public static function split(AgreementRules $rules, CarbonImmutable $start, CarbonImmutable $end, ?int $hourlyOre = null, ?int $monthlyOre = null): ObSplit
    {
        $start = $start->setTimezone(self::TIMEZONE);
        $end = $end->setTimezone(self::TIMEZONE);
        $from = $start->getTimestamp();
        $to = $end->getTimestamp();

        if ($to <= $from) {
            return new ObSplit([], [], 0);
        }

        $intervals = self::intervals($rules, $start, $end, $from, $to);
        $breaks = [$from, $to];

        foreach ($intervals as $interval) {
            $breaks[] = $interval['start'];
            $breaks[] = $interval['end'];
        }

        for ($day = self::day($start)->addDay(); $day->getTimestamp() < $to; $day = self::day($day->addDay())) {
            $breaks[] = $day->getTimestamp();
        }

        $breaks = array_values(array_unique($breaks));
        sort($breaks);

        $seconds = [];
        $notes = [];

        for ($i = 0; $i < count($breaks) - 1; $i++) {
            [$a, $b] = [$breaks[$i], $breaks[$i + 1]];
            $cumulative = [];
            $highestOnly = [];

            foreach ($intervals as $interval) {
                if ($interval['start'] > $a || $interval['end'] < $b) {
                    continue;
                }

                if ($interval['highest_only']) {
                    $highestOnly[$interval['code']] = true;

                    continue;
                }

                $cumulative[$interval['code']] = true;
            }

            $day = CarbonImmutable::createFromTimestamp($a, self::TIMEZONE);
            $codes = array_keys($cumulative + self::highest($rules, array_keys($highestOnly), $day, $hourlyOre, $monthlyOre, $notes));

            foreach ($codes as $code) {
                $seconds[$code] = ($seconds[$code] ?? 0) + $b - $a;
            }
        }

        $minutes = array_filter(array_map(fn (int $value): int => (int) round($value / 60), $seconds), fn (int $value): bool => $value > 0);
        ksort($minutes);

        return new ObSplit($minutes, array_values(array_unique($notes)), (int) round(($to - $from) / 60));
    }

    /**
     * Every window's intervals that touch [from, to), clipped to it.
     *
     * @return list<array{code: string, start: int, end: int, highest_only: bool}>
     */
    private static function intervals(AgreementRules $rules, CarbonImmutable $start, CarbonImmutable $end, int $from, int $to): array
    {
        $dayTypes = $rules->dayTypes();
        $windows = array_values(array_filter((array) ($rules->rules['ob_windows'] ?? []), 'is_array'));
        $intervals = [];
        $last = self::day($end);

        for ($day = self::day($start)->subDays(self::LOOKBACK_DAYS); $day <= $last; $day = self::day($day->addDay())) {
            $weekday = DayCalendar::WEEKDAYS[$day->dayOfWeekIso - 1];
            $names = DayCalendar::namesOf($day);

            foreach ($windows as $window) {
                $code = (string) ($window['code'] ?? '');
                $highestOnly = ($window['overlap'] ?? null) === 'HIGHEST_ONLY';
                $spans = self::spans($window, $dayTypes, $day, $weekday, $names);

                foreach ($spans as [$spanStart, $spanEnd]) {
                    $a = max($from, $spanStart->getTimestamp());
                    $b = min($to, $spanEnd->getTimestamp());

                    if ($a < $b) {
                        $intervals[] = ['code' => $code, 'start' => $a, 'end' => $b, 'highest_only' => $highestOnly];
                    }
                }
            }
        }

        return $intervals;
    }

    /**
     * The spans a window has on a day it may start on.
     *
     * @param  array<mixed>  $window
     * @param  array<string, mixed>  $dayTypes
     * @param  list<string>  $names  the names the day answers to
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private static function spans(array $window, array $dayTypes, CarbonImmutable $day, string $weekday, array $names): array
    {
        if (isset($window['days'])) {
            return in_array($weekday, (array) $window['days'], true)
                ? [[self::at($day, (string) ($window['from'] ?? '00:00')), self::end($day, $window)]]
                : [];
        }

        $definition = (array) ($dayTypes[(string) ($window['day_type'] ?? '')] ?? []);
        $spans = [];

        foreach ((array) ($definition['days'] ?? []) as $entry) {
            $entry = (array) $entry;
            $weekdays = (array) ($entry['weekdays'] ?? []);

            if (! in_array($entry['day'] ?? null, $names, true)) {
                continue;
            }

            if ($weekdays !== [] && ! in_array($weekday, $weekdays, true)) {
                continue;
            }

            $spans[] = [self::at($day, (string) ($entry['from'] ?? '00:00')), self::end($day, $entry)];
        }

        return $spans;
    }

    /**
     * Of the HIGHEST_ONLY codes active on a minute, the one with the highest
     * hourly value; all of them when they cannot be compared.
     *
     * @param  list<string>  $codes
     * @param  list<string>  $notes
     * @return array<string, true>
     */
    private static function highest(AgreementRules $rules, array $codes, CarbonImmutable $day, ?int $hourlyOre, ?int $monthlyOre, array &$notes): array
    {
        if (count($codes) < 2) {
            return array_fill_keys($codes, true);
        }

        sort($codes);
        $values = [];

        foreach ($codes as $code) {
            $value = self::hourlyValue($rules, $code, $day, $hourlyOre, $monthlyOre);

            if ($value === null) {
                $note = __('OB-koderna :codes överlappar men kunde inte jämföras (lön eller sats saknas); alla räknas.', ['codes' => implode(', ', $codes)]);
                $notes[] = is_string($note) ? $note : implode(', ', $codes);

                return array_fill_keys($codes, true);
            }

            $values[$code] = $value;
        }

        $best = $codes[0];

        foreach ($values as $code => $value) {
            if ($value > $values[$best]) {
                $best = $code;
            }
        }

        return [$best => true];
    }

    /** The value of an hour of a code in öre, for comparison only. */
    private static function hourlyValue(AgreementRules $rules, string $code, CarbonImmutable $day, ?int $hourlyOre, ?int $monthlyOre): ?float
    {
        $rate = $rules->rate($code, $day)['rate'] ?? null;

        if ($rate === null) {
            return null;
        }

        return match ($rate['type'] ?? null) {
            'ORE_PER_HOUR' => ($rate['plus_hourly_wage'] ?? false) === true
                ? ($hourlyOre === null ? null : (float) ($rate['ore'] ?? 0) + $hourlyOre)
                : (float) ($rate['ore'] ?? 0),
            'PERCENT_OF_HOURLY' => $hourlyOre === null ? null : (float) ($rate['bp'] ?? 0) * $hourlyOre / 10000,
            'DIVISOR_OF_MONTHLY' => $monthlyOre === null || (float) ($rate['divisor'] ?? 0) <= 0 ? null : $monthlyOre / (float) $rate['divisor'],
            default => null,
        };
    }

    /** @param  array<string, mixed>  $entry */
    private static function end(CarbonImmutable $day, array $entry): CarbonImmutable
    {
        if (isset($entry['to_next_day'])) {
            return self::at(self::day($day->addDay()), (string) $entry['to_next_day']);
        }

        if (($entry['until'] ?? null) === 'NEXT_WEEKDAY') {
            $next = self::day($day->addDay());

            while ($next->isWeekend() || SwedishHolidays::isPublicHoliday($next)) {
                $next = self::day($next->addDay());
            }

            return $next;
        }

        return self::at($day, (string) ($entry['to'] ?? '24:00'));
    }

    /** A wall-clock time on a day; '24:00' is the start of the next day. */
    private static function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        if (preg_match('/^(\d{2}):(\d{2})$/', $time, $parts) !== 1) {
            throw new LogicException("Bad time {$time} in the agreement data.");
        }

        if ($time === '24:00') {
            return self::day($day->addDay());
        }

        return $day->setTime((int) $parts[1], (int) $parts[2]);
    }

    /** Midnight (wall clock) of the date a moment falls on, in Stockholm. */
    private static function day(CarbonImmutable $moment): CarbonImmutable
    {
        return CarbonImmutable::create($moment->year, $moment->month, $moment->day, 0, 0, 0, self::TIMEZONE) ?? throw new LogicException('Bad date.');
    }
}
