<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The size of a company under ÅRL 1 kap. 3 § första stycket 4–5 (S1-R9).
 * Undetermined means NONOX does not know and will not guess: prior years
 * are missing without a confirmed first fiscal year, an employee figure
 * that decides the test is missing, or the legal form adds gifts and
 * grants to the turnover (ideell förening, stiftelse).
 */
enum K3SizeClass: string
{
    case Mindre = 'mindre';
    case Storre = 'storre';
    case Undetermined = 'undetermined';

    public function label(): string
    {
        return match ($this) {
            self::Mindre => __('Mindre företag'),
            self::Storre => __('Större företag'),
            self::Undetermined => __('Storleken går inte att avgöra'),
        };
    }
}
