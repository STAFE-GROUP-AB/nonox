<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

use App\Models\FiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How long one company's staff ledger is kept. 9 kap. 5 § tredje stycket
 * skatteförfarandeförordningen (2011:1261): the data is kept for two years
 * after the end of the calendar year in which the business's beskattningsår
 * ended. For a business the beskattningsår is the räkenskapsår, so an entry
 * is kept until 31 December two years after the calendar year in which the
 * fiscal year containing the entry's date ends. A calendar-year company
 * keeps an entry from 2025 through 2027-12-31; a company with the fiscal
 * year May–April keeps an entry from June 2025 (fiscal year ending
 * 2026-04-30) through 2028-12-31. Enligt AI-utredning 2026-11-02,
 * juristgranskning återstår.
 *
 * The fiscal year is the company's own (fiscal_years) when one covers the
 * date. Otherwise it is derived from the company's first month of the fiscal
 * year as a twelve-month year; if that derived year overlaps a fiscal year
 * the company has (an extended or shortened year nearby), or the company has
 * no fiscal years at all, the date is in doubt and the entry is kept.
 */
final class Retention
{
    public const int YEARS_AFTER_YEAR_END = 2;

    /** @var array<string, ?CarbonImmutable> last day kept, per date */
    private array $memo = [];

    /**
     * @param  Collection<int, FiscalYear>  $fiscalYears  the company's fiscal years
     */
    public function __construct(
        private readonly Collection $fiscalYears,
        private readonly ?int $startMonth,
    ) {}

    /**
     * Nothing dated on or after this can be removed yet: 1 January two years
     * before this year (a fiscal year ends at the latest on the date).
     */
    public static function earliestKept(CarbonImmutable $now): CarbonImmutable
    {
        return $now->startOfYear()->subYears(self::YEARS_AFTER_YEAR_END);
    }

    /** The last day the data from this date must be kept, or null when that cannot be told (keep it). */
    public function keptUntil(CarbonImmutable $date): ?CarbonImmutable
    {
        $key = $date->toDateString();

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $end = $this->fiscalYearEnd($date->startOfDay());

        return $this->memo[$key] = $end?->endOfYear()->addYearsNoOverflow(self::YEARS_AFTER_YEAR_END)->startOfDay();
    }

    public function expired(CarbonImmutable $date, CarbonImmutable $now): bool
    {
        $until = $this->keptUntil($date);

        return $until !== null && $now->startOfDay() > $until;
    }

    private function fiscalYearEnd(CarbonImmutable $date): ?CarbonImmutable
    {
        if ($this->fiscalYears->isEmpty()) {
            return null;
        }

        $covering = $this->fiscalYears->first(fn (FiscalYear $year): bool => $year->contains($date));

        if ($covering instanceof FiscalYear) {
            return $covering->end_date;
        }

        if ($this->startMonth === null || $this->startMonth < 1 || $this->startMonth > 12) {
            return null;
        }

        $start = $date->setDate($date->year, $this->startMonth, 1);

        if ($start > $date) {
            $start = $start->subYear();
        }

        $end = $start->addYear()->subDay();
        $overlaps = $this->fiscalYears->contains(fn (FiscalYear $year): bool => $year->start_date <= $end && $year->end_date >= $start);

        return $overlaps ? null : $end;
    }
}
