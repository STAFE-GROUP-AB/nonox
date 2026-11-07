<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * S1-R2, R2.1: a cash flow statement is required of a större företag only
 * (ÅRL 2 kap. 1 §); NONOX builds the indirect method (K3 7.8 allows it, a
 * product choice). `none` for a större företag is refused by K3Policies,
 * which is given the size.
 */
enum K3CashFlowStatement: string implements K3PolicyValue
{
    case None = 'none';
    case Indirect = 'indirect';

    public function refusal(): ?string
    {
        return null;
    }
}
