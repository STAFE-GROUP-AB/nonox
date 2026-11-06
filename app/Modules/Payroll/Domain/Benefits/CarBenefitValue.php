<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Benefits;

/**
 * A car benefit's value for a year and for each month the employee has the
 * car (a twelfth), in öre, with every part of the calculation.
 */
final readonly class CarBenefitValue
{
    /**
     * @param  array<string, mixed>  $explanation
     */
    public function __construct(
        public int $yearlyOre,
        public int $monthlyOre,
        public array $explanation,
    ) {}
}
