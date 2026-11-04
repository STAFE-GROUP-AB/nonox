<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/**
 * Where a link sent for signing stands (ADR-046). Expired is not stored: a
 * pending link past its expiry is expired (SigningRequest::effectiveStatus).
 */
enum SigningStatus: string
{
    case Pending = 'PENDING';
    case Signed = 'SIGNED';
    case Declined = 'DECLINED';
    case Revoked = 'REVOKED';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Väntar på signering'),
            self::Signed => __('Signerad'),
            self::Declined => __('Avböjd'),
            self::Revoked => __('Återkallad'),
            self::Expired => __('Har gått ut'),
        };
    }

    /** The tone of the status badge (x-ui.badge). */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'accent',
            self::Signed => 'success',
            self::Declined, self::Revoked, self::Expired => 'neutral',
        };
    }
}
