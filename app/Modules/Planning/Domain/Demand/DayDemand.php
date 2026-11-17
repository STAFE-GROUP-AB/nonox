<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Demand;

/**
 * One day's demand against the plan: the forecast, the hours (with the
 * forecast's share where a shape exists, the required and the planned
 * staff), and the day's required and planned staff-hours.
 *
 * Status is OK, UNDER or OVER; NONE when there is no staffing target for
 * the day. Planned staff are people on shifts; open shifts are counted
 * apart.
 */
final readonly class DayDemand
{
    public const string OK = 'OK';

    public const string UNDER = 'UNDER';

    public const string OVER = 'OVER';

    public const string NONE = 'NONE';

    /**
     * @param  array<int, array{hour: int, minutes: int, sales: ?int, need: ?int, planned: float, open: float, status: string}>  $hours  local hour => hour
     * @param  ?string  $shape  HourShape::TILL, HourShape::PROFILE or null (per day only)
     * @param  ?int  $needMinutes  required staff-minutes for the day, null without a target
     */
    public function __construct(
        public string $date,
        public Forecast $forecast,
        public ?string $shape,
        public array $hours,
        public ?int $needMinutes,
        public int $plannedMinutes,
        public int $openMinutes,
        public string $status,
        public bool $hasRules,
    ) {}

    /** @return list<int> the hours that are under-staffed */
    public function underHours(): array
    {
        return array_values(array_map(fn (array $hour): int => $hour['hour'], array_filter($this->hours, fn (array $hour): bool => $hour['status'] === self::UNDER)));
    }

    /** @return list<int> the hours with at least one more person than needed */
    public function overHours(): array
    {
        return array_values(array_map(fn (array $hour): int => $hour['hour'], array_filter($this->hours, fn (array $hour): bool => $hour['status'] === self::OVER)));
    }

    /**
     * Consecutive hours as "11–13" ranges, for a warning.
     *
     * @param  list<int>  $hours
     * @return list<string>
     */
    public static function ranges(array $hours): array
    {
        $ranges = [];
        $start = null;
        $previous = null;

        foreach ($hours as $hour) {
            if ($start !== null && $hour === $previous + 1) {
                $previous = $hour;

                continue;
            }

            if ($start !== null) {
                $ranges[] = sprintf('%02d–%02d', $start, $previous + 1);
            }

            $start = $hour;
            $previous = $hour;
        }

        if ($start !== null) {
            $ranges[] = sprintf('%02d–%02d', $start, $previous + 1);
        }

        return $ranges;
    }
}
