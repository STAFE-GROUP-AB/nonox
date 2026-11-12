<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

use App\Domain\Calendar\SwedishHolidays;
use Carbon\CarbonImmutable;

/**
 * Working hours by weekday (ISO 1 = Monday … 7 = Sunday), each day a list of
 * local [from, to) minutes of the day: 07:00–16:00 with lunch 12–13 is
 * [[420, 720], [780, 960]]. Turned into epoch-minute intervals over a range
 * of dates, public holidays left out.
 */
final readonly class WeekPattern
{
    /**
     * @param  array<int, list<array{0: int, 1: int}>>  $days
     */
    public function __construct(public array $days) {}

    /**
     * One day's start and end with an optional break, the same on the given weekdays.
     *
     * @param  list<int>  $weekdays
     */
    public static function uniform(array $weekdays, int $start, int $end, ?int $breakStart = null, int $breakMinutes = 0): self
    {
        $days = [];

        foreach ($weekdays as $weekday) {
            $days[(int) $weekday] = self::day($start, $end, $breakStart, $breakMinutes);
        }

        return new self($days);
    }

    /**
     * A day's intervals: start to end, less a break that lies inside it.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function day(int $start, int $end, ?int $breakStart, int $breakMinutes): array
    {
        if ($end <= $start) {
            return [];
        }

        if ($breakStart === null || $breakMinutes <= 0 || $breakStart <= $start || $breakStart >= $end) {
            return [[$start, $end]];
        }

        return array_values(array_filter([[$start, $breakStart], [min($end, $breakStart + $breakMinutes), $end]], fn (array $part): bool => $part[1] > $part[0]));
    }

    public function worksOn(int $weekday): bool
    {
        return ($this->days[$weekday] ?? []) !== [];
    }

    /** The first minute of a weekday's work, or null. */
    public function startOn(int $weekday): ?int
    {
        return $this->days[$weekday][0][0] ?? null;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public function intervals(CarbonImmutable $from, CarbonImmutable $to, bool $skipHolidays = true): array
    {
        $intervals = [];

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if ($skipHolidays && SwedishHolidays::isPublicHoliday($day)) {
                continue;
            }

            foreach ($this->days[$day->dayOfWeekIso] ?? [] as [$start, $end]) {
                $intervals[] = [Clock::local($day->toDateString(), $start), Clock::local($day->toDateString(), $end)];
            }
        }

        return $intervals;
    }
}
