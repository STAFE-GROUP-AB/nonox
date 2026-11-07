<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The answer to a yes-or-no K3 policy: comparatives restated at transition
 * (S1-R7.1) and K3 applied before (S1-R7.6).
 */
enum K3YesNo: string implements K3PolicyValue
{
    case Yes = 'yes';
    case No = 'no';

    public function refusal(): ?string
    {
        return null;
    }
}
