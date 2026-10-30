<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

use Carbon\CarbonImmutable;

/**
 * One day of approved absence: the type and the scheduled hours missed.
 */
final readonly class AbsenceInput
{
    public function __construct(
        public string $type,
        public CarbonImmutable $date,
        public string $hours,
    ) {}
}
