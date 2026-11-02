<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support\Staffing;

use Carbon\CarbonImmutable;

/**
 * Time someone actually worked, on the day it started in Sweden: from
 * attested time when it exists, otherwise from the staff ledger. Start and
 * end are known when the source has them; the cost is then estimated the
 * same way as a planned shift's.
 */
final readonly class ActualWork
{
    public function __construct(
        public string $day,
        public ?int $employeeId,
        public int $minutes,
        public ?CarbonImmutable $start = null,
        public ?CarbonImmutable $end = null,
        public int $breakMinutes = 0,
        public ?int $locationId = null,
        public ?int $areaId = null,
    ) {}
}
