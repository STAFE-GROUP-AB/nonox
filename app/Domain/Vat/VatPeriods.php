<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Domain\Company\VatPeriod;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;

/**
 * The VAT periods that touch a fiscal year, for the company's
 * redovisningsperiod (SFL 26 kap. 10 and 11 §§): calendar months, calendar
 * quarters, or the beskattningsår, which for a company is the fiscal year.
 *
 * Months and quarters are always whole calendar periods and are never cut at
 * the fiscal year's edges: with a broken fiscal year from 1 May, the quarter
 * April–June is one period that belongs to both fiscal years and is listed
 * in both. It is declared once and settled once, on its last day, in the
 * fiscal year that day belongs to (ADR-010, #59).
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
        $start = $vatPeriod === VatPeriod::Quarterly ? $fiscalYear->start_date->firstOfQuarter() : $fiscalYear->start_date->startOfMonth();

        while ($start->lessThanOrEqualTo($fiscalYear->end_date)) {
            $end = ($vatPeriod === VatPeriod::Quarterly ? $start->lastOfQuarter() : $start->endOfMonth())->startOfDay();

            $periods[] = ['start' => $start->startOfDay(), 'end' => $end];
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
