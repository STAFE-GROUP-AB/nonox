<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Calendar;

use Carbon\CarbonImmutable;
use LogicException;

/**
 * Public holidays under Lag (1989:253) om allmänna helgdagar: every Sunday,
 * nyårsdagen, trettondedag jul, långfredagen, påskdagen, annandag påsk,
 * Kristi himmelsfärdsdag (the sixth Thursday after Easter), första maj,
 * nationaldagen, pingstdagen (the seventh Sunday after Easter),
 * midsommardagen (the Saturday 20–26 June), alla helgons dag (the Saturday
 * 31 October–6 November), juldagen and annandag jul. Easter is the Sunday
 * after the full moon on or nearest after 21 March, computed by PHP's
 * easter_days for the Gregorian calendar.
 *
 * Midsummer Eve, Christmas Eve and New Year's Eve are not public holidays in
 * the law; whether they are working days is for the agreement or schedule.
 */
final class SwedishHolidays
{
    /** @var array<int, list<string>> holidays per year, Y-m-d */
    private static array $cache = [];

    public static function isHoliday(CarbonImmutable $date): bool
    {
        return $date->isSunday() || in_array($date->toDateString(), self::forYear($date->year), true);
    }

    /** @return list<string> */
    public static function forYear(int $year): array
    {
        if (isset(self::$cache[$year])) {
            return self::$cache[$year];
        }

        $easter = CarbonImmutable::create($year, 3, 21)?->addDays(easter_days($year)) ?? throw new LogicException("No Easter for {$year}.");
        $saturdayBetween = function (int $month, int $day) use ($year): CarbonImmutable {
            $date = CarbonImmutable::create($year, $month, $day) ?? throw new LogicException('Bad date.');

            return $date->isSaturday() ? $date : $date->next(CarbonImmutable::SATURDAY);
        };

        return self::$cache[$year] = array_map(fn (?CarbonImmutable $date): string => (string) $date?->toDateString(), [
            CarbonImmutable::create($year, 1, 1),
            CarbonImmutable::create($year, 1, 6),
            $easter->subDays(2),
            $easter,
            $easter->addDay(),
            $easter->addDays(39),
            CarbonImmutable::create($year, 5, 1),
            CarbonImmutable::create($year, 6, 6),
            $easter->addDays(49),
            $saturdayBetween(6, 20),
            $saturdayBetween(10, 31),
            CarbonImmutable::create($year, 12, 25),
            CarbonImmutable::create($year, 12, 26),
        ]);
    }
}
