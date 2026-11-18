<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/**
 * The PAdES baseline levels (ETSI EN 319 142-1) of ADR-046's amendment, gap 5.
 * S5's engine makes B_B and B_T; B_LT and B_LTA come with S11.
 */
enum SealLevel: string
{
    case B_B = 'B_B';
    case B_T = 'B_T';
    case B_LT = 'B_LT';
    case B_LTA = 'B_LTA';

    public function needsTimestamp(): bool
    {
        return $this !== self::B_B;
    }

    public function label(): string
    {
        return match ($this) {
            self::B_B => 'PAdES B-B',
            self::B_T => 'PAdES B-T',
            self::B_LT => 'PAdES B-LT',
            self::B_LTA => 'PAdES B-LTA',
        };
    }
}
