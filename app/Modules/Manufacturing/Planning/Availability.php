<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning;

use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\Engine\FreeTime;
use Carbon\CarbonImmutable;

/**
 * Takes an approved absence out of a person's working time (ADR-071
 * §13.2). A whole-day absence removes the days; a part-day one (hours per
 * day, as payroll keeps them, or an extent below 100 %) removes that much
 * from the end of each day's working time, and hours that cover the working
 * day remove the whole day. Only the dates and the extent are used, never
 * the kind of absence.
 */
final class Availability
{
    /**
     * @return list<string> the dates inside the range the person is away, wholly or partly
     */
    public static function removeAbsence(FreeTime $free, string $from, string $to, int $extentBp, ?float $hours, CarbonImmutable $rangeFrom, CarbonImmutable $rangeTo): array
    {
        $from = substr($from, 0, 10);
        $to = substr($to, 0, 10);
        $first = max($from, $rangeFrom->toDateString());
        $last = min($to, $rangeTo->toDateString());
        $days = [];

        for ($day = CarbonImmutable::parse($first, Clock::ZONE); $day->toDateString() <= $last; $day = $day->addDay()) {
            $date = $day->toDateString();
            $start = Clock::local($date, 0);
            $end = Clock::local($day->addDay()->toDateString(), 0);
            $days[] = $date;

            $working = $free->minutesBetween($start, $end);
            $minutes = match (true) {
                $hours !== null && $hours > 0 => (int) round($hours * 60),
                $extentBp > 0 && $extentBp < 10_000 => intdiv($working * $extentBp, 10_000),
                default => null,
            };

            if ($minutes === null || $minutes >= $working) {
                $free->remove([[$start, $end]]);

                continue;
            }

            $free->remove(self::lastMinutes($free, $start, $end, $minutes));
        }

        return $days;
    }

    /**
     * The last minutes of working time between two moments.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function lastMinutes(FreeTime $free, int $start, int $end, int $minutes): array
    {
        $within = array_values(array_filter(array_map(
            fn (array $interval): array => [max($interval[0], $start), min($interval[1], $end)],
            $free->intervals(),
        ), fn (array $interval): bool => $interval[1] > $interval[0]));

        $taken = [];

        for ($i = count($within) - 1; $i >= 0 && $minutes > 0; $i--) {
            $take = min($minutes, $within[$i][1] - $within[$i][0]);
            $taken[] = [$within[$i][1] - $take, $within[$i][1]];
            $minutes -= $take;
        }

        return $taken;
    }
}
