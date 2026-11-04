<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/** One step in the audit trail of a link sent for signing (ADR-046). */
enum SigningEventType: string
{
    case Sent = 'SENT';
    case Opened = 'OPENED';
    case Signed = 'SIGNED';
    case Declined = 'DECLINED';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';

    public function label(): string
    {
        return match ($this) {
            self::Sent => __('Skickad'),
            self::Opened => __('Länken hämtades första gången'),
            self::Signed => __('Signerad'),
            self::Declined => __('Avböjd'),
            self::Expired => __('Gick ut'),
            self::Revoked => __('Återkallad'),
        };
    }
}
