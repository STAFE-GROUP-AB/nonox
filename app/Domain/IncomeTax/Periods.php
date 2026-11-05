<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

use App\Models\FiscalYear;

/**
 * Skatteverket's beskattningsperiod for a fiscal year, by its last day
 * (Teknisk information om filöverföring): P1 January–April, P2 May–June,
 * P3 July–August, P4 September–December, e.g. 2026P4.
 */
final class Periods
{
    public static function of(FiscalYear $fiscalYear): string
    {
        $end = $fiscalYear->end_date;
        $part = match (true) {
            $end->month <= 4 => 1,
            $end->month <= 6 => 2,
            $end->month <= 8 => 3,
            default => 4,
        };

        return "{$end->year}P{$part}";
    }
}
