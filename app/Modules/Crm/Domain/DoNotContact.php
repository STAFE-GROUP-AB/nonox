<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/**
 * Why a person must not be contacted (ADR-043): an objection to the
 * processing (GDPR art. 21, the right to object to direct marketing) or a
 * restriction of the processing (art. 18).
 */
enum DoNotContact: string
{
    case Objection = 'OBJECTION';
    case Restriction = 'RESTRICTION';

    public function label(): string
    {
        return match ($this) {
            self::Objection => __('Vill inte bli kontaktad (invändning)'),
            self::Restriction => __('Begränsad behandling'),
        };
    }
}
