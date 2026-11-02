<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

/**
 * The payroll basis of one day (PayrollBasis): minutes per pay code to send
 * to payroll, the OB split behind it, and what the manager should know.
 */
final readonly class DayBasis
{
    /**
     * @param  array<string, int>  $minutes  pay code => minutes, only codes with minutes, sorted by code
     * @param  array<string, int>  $ob  OB code => minutes, as split from the actual time
     * @param  list<Deviation>  $deviations
     */
    public function __construct(
        public array $minutes,
        public array $ob,
        public array $deviations,
    ) {}

    public function minutesFor(string $code): int
    {
        return $this->minutes[$code] ?? 0;
    }
}
