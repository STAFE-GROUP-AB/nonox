<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

use App\Domain\Calendar\SwedishHolidays;
use Carbon\CarbonImmutable;

/**
 * The company's working days (ADR-071 §13.3 step 5 and 6): the weekdays
 * it works and when its day starts, public holidays excluded. Gives the
 * forecast delivery date and counts working days between two dates.
 */
final readonly class WorkingCalendar
{
    /**
     * @param  list<int>  $weekdays  ISO weekdays, 1 = Monday
     */
    public function __construct(public array $weekdays, public int $dayStart) {}

    public function isWorkingDay(CarbonImmutable $date): bool
    {
        return in_array($date->dayOfWeekIso, $this->weekdays, true) && ! SwedishHolidays::isPublicHoliday($date);
    }

    /**
     * The first working day whose start is at or after the finish, plus a
     * buffer of working days (step 5).
     */
    public function deliveryDate(int $finish, int $bufferDays = 0): string
    {
        $day = Clock::at($finish)->startOfDay();

        for ($guard = 0; $guard < 400; $guard++, $day = $day->addDay()) {
            if ($this->isWorkingDay($day) && Clock::local($day->toDateString(), $this->dayStart) >= $finish) {
                break;
            }
        }

        return $this->addWorkingDays($day, $bufferDays)->toDateString();
    }

    public function addWorkingDays(CarbonImmutable $day, int $days): CarbonImmutable
    {
        while ($days > 0) {
            $day = $day->addDay();

            if ($this->isWorkingDay($day)) {
                $days--;
            }
        }

        return $day;
    }

    /** The first moment of work at or after a minute: the start of that day or of the next working day. */
    public function nextStart(int $minute): int
    {
        $day = Clock::at($minute)->startOfDay();

        for ($guard = 0; $guard < 400; $guard++, $day = $day->addDay()) {
            $start = Clock::local($day->toDateString(), $this->dayStart);

            if ($this->isWorkingDay($day) && $start >= $minute) {
                return $start;
            }

            if ($this->isWorkingDay($day) && Clock::date($minute) === $day->toDateString()) {
                return $minute;
            }
        }

        return $minute;
    }

    /**
     * Working days from one date to another: positive when $to is later,
     * counting the working days after $from up to and including $to.
     */
    public function workingDaysBetween(string $from, string $to): int
    {
        if ($from === $to) {
            return 0;
        }

        $sign = $to > $from ? 1 : -1;
        [$low, $high] = $sign === 1 ? [$from, $to] : [$to, $from];
        $count = 0;

        for ($day = CarbonImmutable::parse($low, Clock::ZONE)->addDay(); $day->toDateString() <= $high; $day = $day->addDay()) {
            if ($this->isWorkingDay($day)) {
                $count++;
            }
        }

        return $sign * $count;
    }
}
