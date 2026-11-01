<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Domain;

use App\Modules\Payroll\Support\Calendar\SwedishHolidays;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * The agreement calendar (ADR-033, gap G5, step A2): the named days
 * collective agreements speak of (påskafton, midsommarafton, julafton,
 * nyårsafton, trettondedagsafton, valborgsmässoafton, dagen före Kristi
 * himmelsfärd, skärtorsdagen, pingstafton) next to the law's public
 * holidays, and an agreement's day types (STORHELG, HELGDAG, ...) as time
 * spans on given dates. Computed from the year, with Easter by PHP's
 * easter_days; never a table per year. Pure: no database.
 *
 * A day type entry in the agreement data names a day and may narrow it to
 * weekdays and a clock time: from (default 00:00), and one of to (default
 * 24:00), to_next_day (a time the next day) or until NEXT_WEEKDAY
 * (00:00 of the next Monday–Friday that is not a public holiday).
 */
final class DayCalendar
{
    /** Named days the agreement data may use, with their Swedish names. */
    public const array NAMED_DAYS = [
        'NYARSDAGEN' => 'Nyårsdagen',
        'TRETTONDEDAGSAFTON' => 'Trettondedagsafton',
        'TRETTONDEDAG_JUL' => 'Trettondedag jul',
        'SKARTORSDAGEN' => 'Skärtorsdagen',
        'LANGFREDAGEN' => 'Långfredagen',
        'PASKAFTON' => 'Påskafton',
        'PASKDAGEN' => 'Påskdagen',
        'ANNANDAG_PASK' => 'Annandag påsk',
        'VALBORGSMASSOAFTON' => 'Valborgsmässoafton',
        'FORSTA_MAJ' => 'Första maj',
        'DAGEN_FORE_KRISTI_HIMMELSFARD' => 'Dagen före Kristi himmelsfärdsdag',
        'KRISTI_HIMMELSFARDSDAG' => 'Kristi himmelsfärdsdag',
        'PINGSTAFTON' => 'Pingstafton',
        'PINGSTDAGEN' => 'Pingstdagen',
        'NATIONALDAGEN' => 'Sveriges nationaldag',
        'MIDSOMMARAFTON' => 'Midsommarafton',
        'MIDSOMMARDAGEN' => 'Midsommardagen',
        'ALLA_HELGONS_DAG' => 'Alla helgons dag',
        'JULAFTON' => 'Julafton',
        'JULDAGEN' => 'Juldagen',
        'ANNANDAG_JUL' => 'Annandag jul',
        'NYARSAFTON' => 'Nyårsafton',
    ];

    /** Kinds of day that are not one date a year. PUBLIC_HOLIDAY means the law's holidays other than ordinary Sundays. */
    public const array GENERIC_DAYS = [
        'SATURDAY' => 'Lördag',
        'SUNDAY' => 'Söndag',
        'PUBLIC_HOLIDAY' => 'Helgdag enligt lag',
    ];

    public const array WEEKDAYS = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];

    /** @var array<int, array<string, string>> named day => Y-m-d, per year */
    private static array $cache = [];

    /**
     * The named days of a year.
     *
     * @return array<string, string> name => Y-m-d
     */
    public static function namedDays(int $year): array
    {
        if (isset(self::$cache[$year])) {
            return self::$cache[$year];
        }

        $easter = CarbonImmutable::create($year, 3, 21)?->addDays(easter_days($year)) ?? throw new LogicException("No Easter for {$year}.");
        $date = fn (int $month, int $day): CarbonImmutable => CarbonImmutable::create($year, $month, $day) ?? throw new LogicException('Bad date.');
        $saturdayFrom = fn (int $month, int $day): CarbonImmutable => $date($month, $day)->isSaturday() ? $date($month, $day) : $date($month, $day)->next(CarbonImmutable::SATURDAY);
        $midsummer = $saturdayFrom(6, 20);

        $days = [
            'NYARSDAGEN' => $date(1, 1),
            'TRETTONDEDAGSAFTON' => $date(1, 5),
            'TRETTONDEDAG_JUL' => $date(1, 6),
            'SKARTORSDAGEN' => $easter->subDays(3),
            'LANGFREDAGEN' => $easter->subDays(2),
            'PASKAFTON' => $easter->subDay(),
            'PASKDAGEN' => $easter,
            'ANNANDAG_PASK' => $easter->addDay(),
            'VALBORGSMASSOAFTON' => $date(4, 30),
            'FORSTA_MAJ' => $date(5, 1),
            'DAGEN_FORE_KRISTI_HIMMELSFARD' => $easter->addDays(38),
            'KRISTI_HIMMELSFARDSDAG' => $easter->addDays(39),
            'PINGSTAFTON' => $easter->addDays(48),
            'PINGSTDAGEN' => $easter->addDays(49),
            'NATIONALDAGEN' => $date(6, 6),
            'MIDSOMMARAFTON' => $midsummer->subDay(),
            'MIDSOMMARDAGEN' => $midsummer,
            'ALLA_HELGONS_DAG' => $saturdayFrom(10, 31),
            'JULAFTON' => $date(12, 24),
            'JULDAGEN' => $date(12, 25),
            'ANNANDAG_JUL' => $date(12, 26),
            'NYARSAFTON' => $date(12, 31),
        ];

        return self::$cache[$year] = array_map(fn (CarbonImmutable $day): string => $day->toDateString(), $days);
    }

    /**
     * Every name a date answers to: the named days, SATURDAY or SUNDAY, and
     * PUBLIC_HOLIDAY for the law's holidays.
     *
     * @return list<string>
     */
    public static function namesOf(CarbonImmutable $day): array
    {
        $names = array_keys(array_filter(self::namedDays($day->year), fn (string $date): bool => $date === $day->toDateString()));

        if ($day->isSaturday()) {
            $names[] = 'SATURDAY';
        }

        if ($day->isSunday()) {
            $names[] = 'SUNDAY';
        }

        if (in_array($day->toDateString(), SwedishHolidays::forYear($day->year), true)) {
            $names[] = 'PUBLIC_HOLIDAY';
        }

        return $names;
    }

    /**
     * The spans of an agreement's day types that start on a date.
     *
     * @param  array<string, mixed>  $dayTypes  the agreement's day_types
     * @return list<array{type: string, name: string, day: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public static function spansOn(array $dayTypes, CarbonImmutable $day): array
    {
        $day = $day->startOfDay();
        $names = self::namesOf($day);
        $weekday = self::WEEKDAYS[$day->dayOfWeekIso - 1];
        $spans = [];

        foreach ($dayTypes as $type => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            foreach ((array) ($definition['days'] ?? []) as $entry) {
                $entry = (array) $entry;
                $weekdays = (array) ($entry['weekdays'] ?? []);

                if (! in_array($entry['day'] ?? null, $names, true) || ($weekdays !== [] && ! in_array($weekday, $weekdays, true))) {
                    continue;
                }

                $spans[] = [
                    'type' => (string) $type,
                    'name' => (string) ($definition['name'] ?? $type),
                    'day' => (string) $entry['day'],
                    'start' => self::at($day, (string) ($entry['from'] ?? '00:00')),
                    'end' => self::end($day, $entry),
                ];
            }
        }

        return $spans;
    }

    /**
     * The day types of an agreement that apply on a date (a span starts on
     * it), by type key.
     *
     * @param  array<string, mixed>  $dayTypes
     * @return list<string>
     */
    public static function typesOn(array $dayTypes, CarbonImmutable $day): array
    {
        return array_values(array_unique(array_map(fn (array $span): string => $span['type'], self::spansOn($dayTypes, $day))));
    }

    /**
     * The calendar of a year for an agreement: each date with a named day or
     * a day type, in date order.
     *
     * @param  array<string, mixed>  $dayTypes
     * @return list<array{date: string, names: list<string>, spans: list<array{type: string, name: string, day: string, start: CarbonImmutable, end: CarbonImmutable}>}>
     */
    public static function year(array $dayTypes, int $year): array
    {
        $rows = [];

        for ($day = CarbonImmutable::create($year, 1, 1) ?? throw new LogicException('Bad year.'); $day->year === $year; $day = $day->addDay()) {
            $named = array_values(array_filter(self::namesOf($day), fn (string $name): bool => isset(self::NAMED_DAYS[$name])));
            $spans = array_values(array_filter(self::spansOn($dayTypes, $day), fn (array $span): bool => ! in_array($span['day'], ['SATURDAY', 'SUNDAY'], true)));

            if ($named !== [] || $spans !== []) {
                $rows[] = ['date' => $day->toDateString(), 'names' => $named, 'spans' => $spans];
            }
        }

        return $rows;
    }

    public static function label(string $name): string
    {
        return __(self::NAMED_DAYS[$name] ?? self::GENERIC_DAYS[$name] ?? $name);
    }

    /** @param  array<string, mixed>  $entry */
    private static function end(CarbonImmutable $day, array $entry): CarbonImmutable
    {
        if (isset($entry['to_next_day'])) {
            return self::at($day->addDay(), (string) $entry['to_next_day']);
        }

        if (($entry['until'] ?? null) === 'NEXT_WEEKDAY') {
            $next = $day->addDay();

            while ($next->isWeekend() || in_array($next->toDateString(), SwedishHolidays::forYear($next->year), true)) {
                $next = $next->addDay();
            }

            return $next;
        }

        return self::at($day, (string) ($entry['to'] ?? '24:00'));
    }

    private static function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        if (preg_match('/^(\d{2}):(\d{2})$/', $time, $parts) !== 1) {
            throw new LogicException("Bad time {$time} in the agreement data.");
        }

        return $day->startOfDay()->addHours((int) $parts[1])->addMinutes((int) $parts[2]);
    }
}
