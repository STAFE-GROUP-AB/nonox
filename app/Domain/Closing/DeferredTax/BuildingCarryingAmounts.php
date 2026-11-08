<?php

declare(strict_types=1);

namespace App\Domain\Closing\DeferredTax;

use App\Models\FiscalYear;

/**
 * Where the deferred tax proposal reads the carrying amount of a building
 * from the fixed asset register (ADR-059, A.2.6): the sum of the building's
 * components at the balance date, land excluded. The component register is
 * package S1-P4; until it binds its own implementation,
 * NoBuildingCarryingAmounts answers nothing and a building is entered with
 * its balance sheet accounts instead. One call per proposal, so the query
 * count stays fixed.
 */
interface BuildingCarryingAmounts
{
    /**
     * The register's buildings that can be a deferred tax item, keyed by
     * fixed asset id: a label and the carrying amount in öre on the last day
     * of the fiscal year. Only the current company's assets.
     *
     * @return array<int, array{label: string, carrying_ore: int}>
     */
    public function forYear(FiscalYear $fiscalYear): array;
}
