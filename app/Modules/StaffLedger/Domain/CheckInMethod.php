<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

/** How an entry was registered. */
enum CheckInMethod: string
{
    case KioskPin = 'KIOSK_PIN';
    case KioskQr = 'KIOSK_QR';
    case Mobile = 'MOBILE';
    case Manual = 'MANUAL';
    case Correction = 'CORRECTION';

    public function label(): string
    {
        return match ($this) {
            self::KioskPin => __('Kiosk med kod'),
            self::KioskQr => __('Kiosk med QR-kod'),
            self::Mobile => __('Mobil med platskontroll'),
            self::Manual => __('Manuellt av chef'),
            self::Correction => __('Rättelse av chef'),
        };
    }
}
