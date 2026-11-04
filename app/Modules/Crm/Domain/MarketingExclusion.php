<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/**
 * Why a person in a segment does not get a mailing (ADR-048). The order of
 * the cases is the order of the checks: the first that applies is shown.
 */
enum MarketingExclusion: string
{
    case NoEmail = 'NO_EMAIL';
    case Inactive = 'INACTIVE';
    case Restricted = 'RESTRICTED';
    case Objection = 'OBJECTION';
    case Unsubscribed = 'UNSUBSCRIBED';
    case Suppressed = 'SUPPRESSED';
    case Bounced = 'BOUNCED';
    case Customer = 'CUSTOMER';
    case NeedsConsent = 'NEEDS_CONSENT';
    case Duplicate = 'DUPLICATE';

    public function label(): string
    {
        return match ($this) {
            self::NoEmail => __('Ingen e-postadress'),
            self::Inactive => __('Personen är inaktiv'),
            self::Restricted => __('Begränsad behandling'),
            self::Objection => __('Kontakta inte (invändning)'),
            self::Unsubscribed => __('Har avregistrerat sig från utskick'),
            self::Suppressed => __('Adressen finns på spärrlistan'),
            self::Bounced => __('Adressen studsade vid ett tidigare utskick'),
            self::Customer => __('Kunden är begränsad, inaktiv eller anonymiserad i kundregistret'),
            self::NeedsConsent => __('Kräver samtycke: privatperson, enskild firma eller okänt organisationsnummer'),
            self::Duplicate => __('Samma adress finns redan bland mottagarna'),
        };
    }
}
