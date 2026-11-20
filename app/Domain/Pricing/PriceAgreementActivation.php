<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * How a price agreement became active (ADR-082 P5): signed at a link through
 * the signing engine (ADR-046), or by hand with a recorded reason, for
 * example when the customer signed on paper.
 */
enum PriceAgreementActivation: string
{
    case Signed = 'SIGNED';
    case Manual = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::Signed => __('Signerat via länk'),
            self::Manual => __('Aktiverat för hand'),
        };
    }
}
