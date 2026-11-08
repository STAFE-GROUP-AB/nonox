<?php

declare(strict_types=1);

namespace App\Domain\Mileage;

/**
 * What a trip was for (KJ-R6). Only a tjänsteresa (BUSINESS) can give
 * tax-free mileage; a trip between home and the workplace (COMMUTE) and a
 * private trip are never tax-free (IL 11 kap. 1 §, 12 kap. 26 §). The person
 * who drove chooses and the approver checks; NONOX does not decide.
 */
enum TripKind: string
{
    case Business = 'BUSINESS';
    case Commute = 'COMMUTE';
    case Private = 'PRIVATE';

    public function label(): string
    {
        return match ($this) {
            self::Business => __('Tjänsteresa'),
            self::Commute => __('Resa till eller från arbetet'),
            self::Private => __('Privat resa'),
        };
    }
}
