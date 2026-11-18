<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/** Where a forecast figure came from (ADR-081 gap 5): typed by a person, or computed from the stock history. */
enum ForecastSource: string
{
    case Manual = 'MANUAL';
    case History = 'HISTORY';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Inmatat'),
            self::History => __('Beräknat från historik'),
        };
    }
}
