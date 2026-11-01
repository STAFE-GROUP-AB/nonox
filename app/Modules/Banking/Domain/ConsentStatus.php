<?php

declare(strict_types=1);

namespace App\Modules\Banking\Domain;

/** What the provider says about a consent. */
enum ConsentStatus: string
{
    case Active = 'ACTIVE';
    case Pending = 'PENDING';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';
    case Unknown = 'UNKNOWN';

    public function hasEnded(): bool
    {
        return $this === self::Expired || $this === self::Revoked;
    }
}
