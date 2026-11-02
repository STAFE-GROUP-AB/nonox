<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support\Staffing;

/**
 * What one source of actual time knows about a period: the days it has an
 * answer for (a day with no work on it still counts when the source covers
 * it) and the work on those days.
 */
final readonly class ActualPeriod
{
    /**
     * @param  list<string>  $days  Y-m-d
     * @param  list<ActualWork>  $work
     * @param  int  $openShifts  check-ins without a check-out, left out of the hours
     */
    public function __construct(
        public array $days,
        public array $work,
        public int $openShifts = 0,
    ) {}
}
