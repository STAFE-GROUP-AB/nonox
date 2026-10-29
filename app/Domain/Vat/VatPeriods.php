<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Domain\Company\VatPeriod;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;

/**
 * The VAT periods of a fiscal year for the company's redovisningsperiod:
 * every month, every calendar quarter, or the whole year.
 *
 * TODO(legal-verify): quarterly periods are calendar quarters. Confirm how a
 * quarter that straddles a broken fiscal year is reported.
 */
final class VatPeriods
{
    private const array MONTHS = ['Januari', 'Februari', 'Mars', 'April', 'Maj', 'Juni', 'Juli', 'Augusti', 'September', 'Oktober', 'November', 'December'];

    /**
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public static function of(FiscalYear $fiscalYear, VatPeriod $vatPeriod): array
    {
        if ($vatPeriod === VatPeriod::None) {
            return [];
        }

        if ($vatPeriod === VatPeriod::Yearly) {
            return [['start' => $fiscalYear->start_date, 'end' => $fiscalYear->end_date]];
        }

        $periods = [];
        $start = $fiscalYear->start_date;

        while ($start->lessThanOrEqualTo($fiscalYear->end_date)) {
            $end = $vatPeriod === VatPeriod::Quarterly ? $start->lastOfQuarter() : $start->endOfMonth();
            $end = $end->startOfDay()->min($fiscalYear->end_date);

            $periods[] = ['start' => $start, 'end' => $end];
            $start = $end->addDay();
        }

        return $periods;
    }

    public static function label(CarbonImmutable $start, CarbonImmutable $end): string
    {
        if ($start->isSameMonth($end)) {
            return __(self::MONTHS[$start->month - 1]).' '.$start->year;
        }

        return "{$start->toDateString()} – {$end->toDateString()}";
    }
}
