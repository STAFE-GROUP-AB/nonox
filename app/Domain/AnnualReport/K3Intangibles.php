<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * S1-R10, K3 18.7: the expensing or the capitalisation model, applied to every
 * internally generated intangible. S1 supports the expensing model only.
 */
enum K3Intangibles: string implements K3PolicyValue
{
    case Expense = 'expense';
    case Capitalise = 'capitalise';

    public function refusal(): ?string
    {
        return match ($this) {
            self::Expense => null,
            self::Capitalise => (string) __('NONOX stöder bara kostnadsföringsmodellen för utgifter för eget utvecklingsarbete (K3 18.7). Aktiveringsmodellen, med fond för utvecklingsutgifter, stöds inte.'),
        };
    }
}
