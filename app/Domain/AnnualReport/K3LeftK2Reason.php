<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * S1-R7.6, BFNAR 2025:3 transitional provision 2: a company that leaves K2
 * because it may no longer apply K2 as worded by BFNAR 2025:2 may use chapter
 * 35 even if it applied K3 before.
 */
enum K3LeftK2Reason: string implements K3PolicyValue
{
    case Forced20252 = 'forced_2025_2';
    case Voluntary = 'voluntary';

    public function refusal(): ?string
    {
        return null;
    }
}
