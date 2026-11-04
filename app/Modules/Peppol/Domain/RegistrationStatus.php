<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Domain;

/**
 * Whether the company can be found on the Peppol network as a receiver:
 * its identifier published in the access-point provider's SMP (and so in
 * the SML), which only the provider can do, after its own customer
 * identification (ADR-050).
 */
enum RegistrationStatus: string
{
    case NotRegistered = 'NOT_REGISTERED';
    case Pending = 'PENDING';
    case Registered = 'REGISTERED';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::NotRegistered => __('Inte registrerat'),
            self::Pending => __('Väntar på Peppol-operatören'),
            self::Registered => __('Registrerat i Peppol'),
            self::Failed => __('Registreringen misslyckades'),
        };
    }
}
