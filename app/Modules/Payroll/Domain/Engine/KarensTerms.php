<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

/**
 * A karensavdrag a collective agreement counts in time: a share of the
 * weekly working hours (percentOfWeeklyHoursBp of weeklyHours) without
 * sick pay, valued at the agreement's hour value. weeklyHoursSource says
 * where the weekly hours come from: AVERAGE (the employment's, or the
 * average week of an irregular schedule) or AGREED (the weekly hours agreed
 * in advance, the employment's own; Detaljhandelsavtalet § 15.4).
 */
final readonly class KarensTerms
{
    public const string AVERAGE = 'AVERAGE';

    public const string AGREED = 'AGREED';

    public function __construct(
        public int $percentOfWeeklyHoursBp,
        public string $weeklyHours,
        public string $weeklyHoursSource,
        public string $source,
    ) {}
}
