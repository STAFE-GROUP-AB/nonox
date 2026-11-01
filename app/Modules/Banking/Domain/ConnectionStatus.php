<?php

declare(strict_types=1);

namespace App\Modules\Banking\Domain;

/**
 * A bank connection's life: ACTIVE while the consent is valid, EXPIRED when
 * the bank's consent ran out or was withdrawn in the bank, REVOKED when a
 * user ended it in NONOX. Only an ACTIVE connection is fetched.
 */
enum ConnectionStatus: string
{
    case Active = 'ACTIVE';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Aktiv'),
            self::Expired => __('Medgivandet har gått ut'),
            self::Revoked => __('Bortkopplad'),
        };
    }
}
