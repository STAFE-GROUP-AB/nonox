<?php

declare(strict_types=1);

namespace App\Domain\Closing\DeferredTax;

use App\Models\FiscalYear;

/**
 * The default until the component register (S1-P4) binds its own
 * BuildingCarryingAmounts: no building comes from the register.
 */
final class NoBuildingCarryingAmounts implements BuildingCarryingAmounts
{
    public function forYear(FiscalYear $fiscalYear): array
    {
        return [];
    }
}
