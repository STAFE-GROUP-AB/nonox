<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

/**
 * What a correction does to the entry it refers to. The original is never
 * changed; it is shown together with the correction.
 */
enum CorrectionKind: string
{
    /** The registration should not have happened (wrong person, double tap). */
    case Void = 'VOID';
    /** It happened, but at the time given in the correction. */
    case Time = 'TIME';

    public function label(): string
    {
        return $this === self::Void ? __('Felaktig registrering') : __('Ändrad tid');
    }
}
