<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The size of a company under ÅRL 1 kap. 3 § första stycket 4–5 (S1-R9).
 * Undetermined means NONOX does not know and will not guess: the two
 * readings of "mer än ett" disagree, prior years are missing, or an
 * employee figure that decides the test is missing.
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
