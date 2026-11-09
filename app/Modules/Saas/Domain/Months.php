<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

use Carbon\CarbonImmutable;

/** Months as "Y-m-01" strings, compared as text. */
final class Months
{
    public static function of(string $date): string
    {
        return substr($date, 0, 7).'-01';
    }

    public static function add(string $month, int $months): string
    {
        return CarbonImmutable::parse($month)->startOfMonth()->addMonthsNoOverflow($months)->toDateString();
    }

    /** The month's last day, the instant MRR is measured at (SA-R3). */
    public static function end(string $month): string
    {
        return CarbonImmutable::parse($month)->endOfMonth()->toDateString();
    }

    public static function between(string $from, string $to): int
    {
        [$fromYear, $fromMonth] = array_map(intval(...), explode('-', $from));
        [$toYear, $toMonth] = array_map(intval(...), explode('-', $to));

        return ($toYear - $fromYear) * 12 + ($toMonth - $fromMonth);
    }

    /** @return list<string> every month from $from to $to inclusive */
    public static function range(string $from, string $to): array
    {
        $months = [];

        for ($month = self::of($from); $month <= $to; $month = self::add($month, 1)) {
            $months[] = $month;
        }

        return $months;
    }

    /** "oktober 2026" */
    public static function label(string $month): string
    {
        return CarbonImmutable::parse($month)->locale('sv')->isoFormat('MMMM YYYY');
    }

    /** "okt. 2026", for table heads */
    public static function short(string $month): string
    {
        return CarbonImmutable::parse($month)->locale('sv')->isoFormat('MMM YYYY');
    }
}
