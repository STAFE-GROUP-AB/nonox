<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/**
 * The legal basis for keeping a contact person (GDPR art. 6.1 b, f and a):
 * a contract with the person or their employer, legitimate interest (the
 * normal case for a contact at a business), or consent.
 *
 * TODO(legal-verify): the balancing test that legitimate interest rests on
 * (docs/manual/sv/crm/gdpr-i-crm.md).
 */
enum LegalBasis: string
{
    case Agreement = 'AGREEMENT';
    case LegitimateInterest = 'LEGITIMATE_INTEREST';
    case Consent = 'CONSENT';

    public function label(): string
    {
        return match ($this) {
            self::Agreement => __('Avtal'),
            self::LegitimateInterest => __('Berättigat intresse'),
            self::Consent => __('Samtycke'),
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Agreement => __('Personen eller dess arbetsgivare har ett avtal med er, och uppgifterna behövs för avtalet.'),
            self::LegitimateInterest => __('Det vanliga för en kontaktperson på ett företag: ni har ett berättigat intresse av att kontakta personen i dess yrkesroll.'),
            self::Consent => __('Personen har själv samtyckt. Ange datumet. Ett samtycke kan återkallas när som helst.'),
        };
    }
}
