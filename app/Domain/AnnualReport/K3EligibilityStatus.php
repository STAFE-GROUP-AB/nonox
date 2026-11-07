<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The answer of Bokslut → Regelverk for one fiscal year (S1-R1): K2 may be
 * chosen, K3 is required, NONOX still lacks data or answers, or the year is
 * outside what NONOX assesses (legal form, older K2 wording).
 */
enum K3EligibilityStatus: string
{
    case K2Allowed = 'k2_allowed';
    case K3Required = 'k3_required';
    case Undetermined = 'undetermined';
    case NotAssessed = 'not_assessed';

    public function label(): string
    {
        return match ($this) {
            self::K2Allowed => __('Företaget får välja K2 eller K3'),
            self::K3Required => __('Företaget ska tillämpa K3'),
            self::Undetermined => __('Uppgifter saknas för att avgöra om K2 får användas'),
            self::NotAssessed => __('NONOX bedömer inte K2-villkoren för det här året'),
        };
    }
}
