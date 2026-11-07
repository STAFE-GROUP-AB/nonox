<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * ADR-059 §3.2.5: how fixed-price contracts are recognised. All three are
 * accepted as the company's own statement; NONOX computes neither method.
 */
enum K3FixedPriceContracts: string implements K3PolicyValue
{
    case CompletedContract = 'completed_contract_23_31';
    case PercentageOfCompletion = 'percentage_of_completion';
    case None = 'none';

    public function refusal(): ?string
    {
        return null;
    }
}
