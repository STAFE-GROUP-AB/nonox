<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * K3 17.4 (S1-R3, S1-R3.1): the company's component policy, as decided by
 * H4 (2026-11-08, under the owner's mandate, open for the consultant's
 * review). Buildings are always split into land and components; other assets
 * only when the company judges the difference in consumption of significant
 * parts to be material. NONOX sets no amount threshold for "betydande" or
 * "väsentlig"; the annual report states that components were considered.
 * The single value is the person's confirmation that the assets were tried
 * against that policy.
 */
enum K3Components: string implements K3PolicyValue
{
    case BuildingsAlwaysOthersByChoice = 'buildings_always_others_by_choice';

    public function refusal(): ?string
    {
        return null;
    }
}
