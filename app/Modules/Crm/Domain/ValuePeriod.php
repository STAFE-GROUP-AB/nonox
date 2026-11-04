<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/** What an agreement's value is per (ADR-046). */
enum ValuePeriod: string
{
    case Month = 'MONTH';
    case Quarter = 'QUARTER';
    case Year = 'YEAR';
    case Total = 'TOTAL';

    public function label(): string
    {
        return match ($this) {
            self::Month => __('per månad'),
            self::Quarter => __('per kvartal'),
            self::Year => __('per år'),
            self::Total => __('totalt'),
        };
    }
}
