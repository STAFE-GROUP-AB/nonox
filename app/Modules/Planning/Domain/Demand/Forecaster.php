<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Demand;

use App\Domain\Calendar\SwedishHolidays;
use Carbon\CarbonImmutable;

/**
 * The deterministic sales forecast of ADR-037 gap 1. For a day: the average
 * of the same weekday in the last N weeks before the day (and before today,
 * so a day weeks ahead uses the weeks already booked), skipping public
 * holidays, eves and days without sales; the skipped weeks are not replaced.
 * A public holiday or an eve is forecast from the same holiday last year
 * when the books have sales that day, else it has no forecast. An override
 * replaces the figure and keeps the computed one for reference. No trend, no
 * seasonality, no learning: a figure anyone can recompute.
 */
final class Forecaster
{
    public const int DEFAULT_WEEKS = 6;

    public const int MIN_WEEKS = 1;

    public const int MAX_WEEKS = 26;

    /**
     * @param  list<CarbonImmutable>  $days
     * @param  array<string, int>  $sales  Y-m-d => öre: the books' daily sales for the lookback and last year's holidays
     * @param  array<string, array{amount: int, reason: string}>  $overrides  Y-m-d => the person's figure
     * @return array<string, Forecast> Y-m-d => forecast
     */
    public static function forDays(array $days, array $sales, array $overrides, int $weeks, CarbonImmutable $today): array
    {
        $forecasts = [];

        foreach ($days as $day) {
            $forecasts[$day->toDateString()] = self::forDay($day, $sales, $overrides, $weeks, $today);
        }

        return $forecasts;
    }

    /**
     * @param  array<string, int>  $sales
     * @param  array<string, array{amount: int, reason: string}>  $overrides
     */
    public static function forDay(CarbonImmutable $day, array $sales, array $overrides, int $weeks, CarbonImmutable $today): Forecast
    {
        $date = $day->toDateString();
        $computed = self::computed($day, $sales, $weeks, $today);
        $override = $overrides[$date] ?? null;

        if ($override === null) {
            return $computed;
        }

        return new Forecast($date, $override['amount'], Forecast::OVERRIDE, $computed->basis, $computed->holiday, $computed->amountOre, $override['reason']);
    }

    /**
     * The first day the books must be read from for a forecast of the days
     * from $from on.
     */
    public static function lookbackFrom(CarbonImmutable $from, CarbonImmutable $today, int $weeks): CarbonImmutable
    {
        return $from->min($today)->subWeeks(self::clampWeeks($weeks) + 1)->startOfDay();
    }

    /**
     * The same holiday or eve last year for each day that is one.
     *
     * @param  list<CarbonImmutable>  $days
     * @return list<string> Y-m-d
     */
    public static function lastYearHolidays(array $days): array
    {
        $dates = [];

        foreach ($days as $day) {
            $last = self::sameHolidayLastYear($day);

            if ($last !== null) {
                $dates[] = $last;
            }
        }

        return array_values(array_unique($dates));
    }

    public static function clampWeeks(int $weeks): int
    {
        return max(self::MIN_WEEKS, min(self::MAX_WEEKS, $weeks));
    }

    /** The key of the public holiday or eve on a day, or null. */
    public static function holidayKey(CarbonImmutable $day): ?string
    {
        $key = array_search($day->toDateString(), SwedishHolidays::named($day->year) + SwedishHolidays::eves($day->year), true);

        return is_string($key) ? $key : null;
    }

    public static function sameHolidayLastYear(CarbonImmutable $day): ?string
    {
        $key = self::holidayKey($day);

        if ($key === null) {
            return null;
        }

        $year = $day->year - 1;

        return (SwedishHolidays::named($year) + SwedishHolidays::eves($year))[$key] ?? null;
    }

    /** @param  array<string, int>  $sales */
    private static function computed(CarbonImmutable $day, array $sales, int $weeks, CarbonImmutable $today): Forecast
    {
        $date = $day->toDateString();

        if (self::holidayKey($day) !== null) {
            $last = self::sameHolidayLastYear($day);
            $amount = $last === null ? null : ($sales[$last] ?? null);
            $holiday = SwedishHolidays::nameOf($day);

            return $amount !== null && $amount > 0
                ? new Forecast($date, $amount, Forecast::HOLIDAY, [(string) $last], $holiday)
                : new Forecast($date, null, Forecast::NONE, [], $holiday);
        }

        $weeks = self::clampWeeks($weeks);
        $back = 1;

        // A day weeks ahead starts from the newest same weekday before today.
        while ($day->subWeeks($back)->greaterThanOrEqualTo($today->startOfDay())) {
            $back++;
        }

        $basis = [];
        $sum = 0;

        for ($k = $back; $k < $back + $weeks; $k++) {
            $candidate = $day->subWeeks($k);
            $value = $sales[$candidate->toDateString()] ?? 0;

            if (self::holidayKey($candidate) !== null || $value <= 0) {
                continue;
            }

            $basis[] = $candidate->toDateString();
            $sum += $value;
        }

        if ($basis === []) {
            return new Forecast($date, null, Forecast::NONE, []);
        }

        $count = count($basis);

        // Half up to the öre, in integers.
        return new Forecast($date, intdiv(2 * $sum + $count, 2 * $count), Forecast::AVERAGE, $basis);
    }
}
