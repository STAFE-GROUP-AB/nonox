<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use Carbon\CarbonImmutable;

/**
 * Rules for the length and boundaries of a räkenskapsår (AGENTS.md §6.2).
 */
final class FiscalYearRules
{
    /**
     * A first (or changed) fiscal year may be shorter or longer than twelve
     * months, but never longer than eighteen (BFL 3 kap).
     *
     * TODO(legal-verify): AGENTS.md §6.2 says 6-18 months. Confirm whether BFL
     * sets a minimum length at all, or only the 18-month maximum.
     */
    public const int MIN_MONTHS = 6;

    public const int MAX_MONTHS = 18;

    public const int NORMAL_MONTHS = 12;

    /**
     * The fiscal year that contains the given date, for a company whose year
     * starts on the first day of $startMonth.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function yearContaining(CarbonImmutable $date, int $startMonth): array
    {
        $start = $date->startOfMonth()->month($startMonth);

        if ($start->greaterThan($date)) {
            $start = $start->subYear();
        }

        return [$start, $start->addMonths(self::NORMAL_MONTHS)->subDay()];
    }

    public static function months(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) round($start->diffInMonths($end->addDay()));
    }

    /**
     * Returns a Swedish explanation of what is wrong, or null when the
     * period is a valid fiscal year.
     */
    public static function problem(CarbonImmutable $start, CarbonImmutable $end, bool $mayDeviate): ?string
    {
        if ($start->day !== 1) {
            return __('Räkenskapsåret måste börja den första dagen i en månad.');
        }

        if (! $end->isSameDay($end->endOfMonth())) {
            return __('Räkenskapsåret måste sluta den sista dagen i en månad.');
        }

        if ($end->lessThanOrEqualTo($start)) {
            return __('Slutdatumet måste komma efter startdatumet.');
        }

        $months = self::months($start, $end);

        if (! $mayDeviate && $months !== self::NORMAL_MONTHS) {
            return __('Ett räkenskapsår ska vara tolv månader.');
        }

        if ($months < self::MIN_MONTHS || $months > self::MAX_MONTHS) {
            return __('Ett första räkenskapsår får vara mellan :min och :max månader.', [
                'min' => self::MIN_MONTHS,
                'max' => self::MAX_MONTHS,
            ]);
        }

        return null;
    }
}
