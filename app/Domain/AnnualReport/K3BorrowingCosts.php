<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * K3 25.3/25.7 (ADR-059 §1.1): borrowing costs are expensed, or included in the
 * cost of an asset. S1 supports expensing only.
 */
enum K3BorrowingCosts: string implements K3PolicyValue
{
    case Expense = 'expense';
    case Capitalise = 'capitalise';

    public function refusal(): ?string
    {
        return match ($this) {
            self::Expense => null,
            self::Capitalise => (string) __('NONOX stöder bara att låneutgifter kostnadsförs. Låneutgifter som räknas in i en tillgångs anskaffningsvärde stöds inte.'),
        };
    }
}
