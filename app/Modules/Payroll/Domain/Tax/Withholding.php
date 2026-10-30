<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Tax;

/**
 * A withheld tax amount and how it was reached, for the payslip's
 * explanation (PAYROLL_MODULE.md B.1, point 4).
 */
final readonly class Withholding
{
    /**
     * @param  array<string, mixed>  $explanation
     */
    public function __construct(public int $taxOre, public array $explanation) {}
}
