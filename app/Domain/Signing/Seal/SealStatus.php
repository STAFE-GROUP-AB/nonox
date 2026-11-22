<?php

declare(strict_types=1);

namespace App\Domain\Signing\Seal;

/**
 * Where an envelope's seal is (ADR-046 amendment, gap 5, package S10). An
 * envelope that is never sealed (sealing off, no engine or no certificate
 * when it completed) has no status at all.
 */
enum SealStatus: string
{
    /** The queue is sealing it; its completion mails wait for the seal. */
    case Pending = 'PENDING';

    /** The finished file is sealed at the level stored on the envelope. */
    case Sealed = 'SEALED';

    /** It could not be sealed; the mails went with the unsealed file. */
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Förseglas'),
            self::Sealed => __('Förseglad'),
            self::Failed => __('Kunde inte förseglas'),
        };
    }
}
