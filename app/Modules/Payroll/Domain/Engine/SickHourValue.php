<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

/**
 * A collective agreement's own value of an hour (or a day) of sick pay for
 * a monthly salary (sjuklönelagen 2 § andra stycket lets a central
 * agreement set the detailed calculation). Without it the engine uses
 * HourlyValue (monthly × 12 / 52 / weekly hours), which the law does not
 * prescribe either.
 *
 * - MONTHLY_PER_WEEK: monthly × monthsPerYear / (weeksPerYear × weekly
 *   hours), as Visita–Unionen (12/52) and Handelns tjänstemannaavtal
 *   (12,2/52).
 * - MONTH_WORKING_HOURS: monthly / the working hours of the calendar
 *   month, as Gröna Riks; monthWorkingHours is filled from the schedule.
 * - DAY_PERCENT: dayBp of the monthly salary per full sick day and
 *   1/hourDivisor per hour of a part day, as Detaljhandelsavtalet
 *   (4,6 % and 1/166).
 */
final readonly class SickHourValue
{
    public const string MONTHLY_PER_WEEK = 'MONTHLY_PER_WEEK';

    public const string MONTH_WORKING_HOURS = 'MONTH_WORKING_HOURS';

    public const string DAY_PERCENT = 'DAY_PERCENT';

    public const array METHODS = [self::MONTHLY_PER_WEEK, self::MONTH_WORKING_HOURS, self::DAY_PERCENT];

    public function __construct(
        public string $method,
        public string $source,
        public ?string $monthsPerYear = null,
        public ?string $weeksPerYear = null,
        public ?string $monthWorkingHours = null,
        public ?int $dayBp = null,
        public ?string $hourDivisor = null,
    ) {}
}
