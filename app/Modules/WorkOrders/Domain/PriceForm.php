<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Domain;

/**
 * How a work order or change order is priced (ADR-074 §2, KtjL 36 §).
 */
enum PriceForm: string
{
    /** Löpande räkning: hours at the agreed rates and material at the agreed price. */
    case TimeAndMaterial = 'TIME_AND_MATERIAL';

    /** Fast pris: a contract sum. */
    case Fixed = 'FIXED';

    /** Löpande räkning with an approximate price including VAT (ungefärligt pris, KtjL 36 §). */
    case Estimate = 'ESTIMATE';

    public function label(): string
    {
        return match ($this) {
            self::TimeAndMaterial => __('Löpande räkning'),
            self::Fixed => __('Fast pris'),
            self::Estimate => __('Löpande räkning med ungefärligt pris'),
        };
    }

    /** Billed from the recorded time and material. */
    public function billsEntries(): bool
    {
        return $this !== self::Fixed;
    }
}
