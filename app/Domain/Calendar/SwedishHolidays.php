<?php

declare(strict_types=1);

namespace App\Domain\Calendar;

use Carbon\CarbonImmutable;
use LogicException;

/**
 * The Swedish public holidays and the eves collective agreements use.
 * Pure: computed from the year, cached per year, no database.
 *
 * Source: lagen (1989:253) om allmänna helgdagar — every Sunday, nyårsdagen,
 * trettondedag jul, långfredagen, påskdagen, annandag påsk, Kristi
 * himmelsfärdsdag (Easter + 39 days), första maj, Sveriges nationaldag,
 * pingstdagen (Easter + 49 days), midsommardagen (the Saturday 20–26 June),
 * alla helgons dag (the Saturday 31 October–6 November), juldagen and
 * annandag jul. Easter is computed by PHP's easter_days (Gregorian computus).
 *
 * TODO(legal-verify): confirm against the current SFS text (riksdagen.se)
 * that no holiday has been added or moved; eves are not public holidays in
 * the law and are listed because collective agreements use them.
 */
final class SwedishHolidays
{
    /** Swedish names of the dated holidays and eves, by key (same keys as the agreement calendar). */
    public const array LABELS = [
        'NYARSDAGEN' => 'Nyårsdagen',
        'TRETTONDEDAG_JUL' => 'Trettondedag jul',
        'LANGFREDAGEN' => 'Långfredagen',
        'PASKAFTON' => 'Påskafton',
        'PASKDAGEN' => 'Påskdagen',
        'ANNANDAG_PASK' => 'Annandag påsk',
        'KRISTI_HIMMELSFARDSDAG' => 'Kristi himmelsfärdsdag',
        'FORSTA_MAJ' => 'Första maj',
        'NATIONALDAGEN' => 'Sveriges nationaldag',
        'PINGSTDAGEN' => 'Pingstdagen',
        'MIDSOMMARAFTON' => 'Midsommarafton',
        'MIDSOMMARDAGEN' => 'Midsommardagen',
        'ALLA_HELGONS_DAG' => 'Alla helgons dag',
        'JULAFTON' => 'Julafton',
        'JULDAGEN' => 'Juldagen',
        'ANNANDAG_JUL' => 'Annandag jul',
        'NYARSAFTON' => 'Nyårsafton',
    ];

    /** @var array<int, array{holidays: array<string, string>, eves: array<string, string>}> per year, Y-m-d */
    private static array $cache = [];

    /**
     * The law's dated holidays of a year (not ordinary Sundays), in calendar
     * order of the law's list.
     *
     * @return list<string> Y-m-d
     */
    public static function forYear(int $year): array
    {
        return array_values(self::named($year));
    }

    /**
     * The law's dated holidays of a year by key.
     *
     * @return array<string, string> key => Y-m-d
     */
    public static function named(int $year): array
    {
        return self::compute($year)['holidays'];
    }

    /**
     * The eves agreements use: påskafton, midsommarafton, julafton, nyårsafton.
     *
     * @return array<string, string> key => Y-m-d
     */
    public static function eves(int $year): array
    {
        return self::compute($year)['eves'];
    }

    /** A Sunday or one of the law's dated holidays. */
    public static function isHoliday(CarbonImmutable $date): bool
    {
        return $date->isSunday() || self::isPublicHoliday($date);
    }

    /** One of the law's dated holidays (an ordinary Sunday is not). */
    public static function isPublicHoliday(CarbonImmutable $date): bool
    {
        return in_array($date->toDateString(), self::named($date->year), true);
    }

    public static function isEve(CarbonImmutable $date): bool
    {
        return in_array($date->toDateString(), self::eves($date->year), true);
    }

    /** The Swedish name of a dated holiday or eve, or null for any other day (ordinary Sundays included). */
    public static function nameOf(CarbonImmutable $date): ?string
    {
        $key = array_search($date->toDateString(), self::named($date->year) + self::eves($date->year), true);

        if ($key === false) {
            return null;
        }

        $label = __(self::LABELS[$key]);

        return is_string($label) ? $label : self::LABELS[$key];
    }

    /** @return array{holidays: array<string, string>, eves: array<string, string>} */
    private static function compute(int $year): array
    {
        if (isset(self::$cache[$year])) {
            return self::$cache[$year];
        }

        $date = fn (int $month, int $day): CarbonImmutable => CarbonImmutable::create($year, $month, $day) ?? throw new LogicException("Bad date {$year}-{$month}-{$day}.");
        $saturdayFrom = fn (int $month, int $day): CarbonImmutable => $date($month, $day)->isSaturday() ? $date($month, $day) : $date($month, $day)->next(CarbonImmutable::SATURDAY);
        $easter = $date(3, 21)->addDays(easter_days($year));
        $midsummer = $saturdayFrom(6, 20);

        $holidays = [
            'NYARSDAGEN' => $date(1, 1),
            'TRETTONDEDAG_JUL' => $date(1, 6),
            'LANGFREDAGEN' => $easter->subDays(2),
            'PASKDAGEN' => $easter,
            'ANNANDAG_PASK' => $easter->addDay(),
            'KRISTI_HIMMELSFARDSDAG' => $easter->addDays(39),
            'FORSTA_MAJ' => $date(5, 1),
            'NATIONALDAGEN' => $date(6, 6),
            'PINGSTDAGEN' => $easter->addDays(49),
            'MIDSOMMARDAGEN' => $midsummer,
            'ALLA_HELGONS_DAG' => $saturdayFrom(10, 31),
            'JULDAGEN' => $date(12, 25),
            'ANNANDAG_JUL' => $date(12, 26),
        ];

        $eves = [
            'PASKAFTON' => $easter->subDay(),
            'MIDSOMMARAFTON' => $midsummer->subDay(),
            'JULAFTON' => $date(12, 24),
            'NYARSAFTON' => $date(12, 31),
        ];

        $toString = fn (CarbonImmutable $day): string => $day->toDateString();

        return self::$cache[$year] = [
            'holidays' => array_map($toString, $holidays),
            'eves' => array_map($toString, $eves),
        ];
    }
}
