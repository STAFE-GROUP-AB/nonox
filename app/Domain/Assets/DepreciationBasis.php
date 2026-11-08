<?php

declare(strict_types=1);

namespace App\Domain\Assets;

/**
 * What a depreciation plan writes off (ADR-059 A.2.4). Cost: acquisition
 * value less residual value from the first month, so a missed month is
 * caught up; every asset registered before components has it. Carrying
 * amount: for a component created by a split, the carrying amount at the
 * split (cost less the depreciation allocated to it, less residual value)
 * over the remaining life from the split, so no month before the split is
 * ever booked again (K3 35.18 "får inte räknas om retroaktivt"; a changed
 * life is a change of estimate, K3 17.17).
 */
enum DepreciationBasis: string
{
    case Cost = 'COST';
    case CarryingAmount = 'CARRYING_AMOUNT';
}
