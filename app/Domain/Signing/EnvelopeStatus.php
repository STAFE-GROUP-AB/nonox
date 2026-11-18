<?php

declare(strict_types=1);

namespace App\Domain\Signing;

/**
 * Where an envelope stands (ADR-046 amendment, gap 1). Expired is not
 * stored: an envelope that is out past its deadline is expired
 * (SigningEnvelope::effectiveStatus).
 */
enum EnvelopeStatus: string
{
    case Draft = 'DRAFT';
    case Out = 'OUT';
    case Completed = 'COMPLETED';
    case Declined = 'DECLINED';
    case Voided = 'VOIDED';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Utkast'),
            self::Out => __('Ute för signering'),
            self::Completed => __('Signerat av alla'),
            self::Declined => __('Avböjt'),
            self::Voided => __('Tillbakadraget'),
            self::Expired => __('Har gått ut'),
        };
    }

    /** The tone of the status badge (x-ui.badge). */
    public function tone(): string
    {
        return match ($this) {
            self::Out => 'accent',
            self::Completed => 'success',
            default => 'neutral',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Declined, self::Voided], true);
    }
}
