<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

/**
 * Overtime and mertid of one calendar week for a monthly-paid employee
 * (PayrollBasis::week): the minutes of each kind, what goes to payroll per
 * pay code, and what the manager should know.
 */
final readonly class WeekBasis
{
    /**
     * @param  array<string, int>  $minutes  pay code => minutes to send, sorted by code
     * @param  list<Deviation>  $deviations
     */
    public function __construct(
        public int $overtimeMinutes,
        public int $extraTimeMinutes,
        public array $minutes,
        public array $deviations,
    ) {}
}
