<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

/**
 * Kinds of ledger entries. CHECK_IN and CHECK_OUT come from the person
 * (kiosk or mobile); MANUAL is registered by a manager with a reason;
 * CORRECTION refers to an earlier entry and voids it or moves its time.
 */
enum EntryType: string
{
    case CheckIn = 'CHECK_IN';
    case CheckOut = 'CHECK_OUT';
    case Manual = 'MANUAL';
    case Correction = 'CORRECTION';

    public function label(): string
    {
        return match ($this) {
            self::CheckIn => __('Incheckning'),
            self::CheckOut => __('Utcheckning'),
            self::Manual => __('Manuell registrering'),
            self::Correction => __('Rättelse'),
        };
    }
}
