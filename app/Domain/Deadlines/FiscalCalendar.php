<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

use App\Domain\Accounting\FiscalYearRules;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * The company's fiscal years over a span, for Mina datum: the years it has
 * in NONOX, and after the last of them the years that will follow (as
 * FiscalYearRules::nextYear makes them). With no year in NONOX, the year
 * that contains the span's start by the company's first fiscal month. A
 * projected year is an unsaved FiscalYear, so VatPeriods can read it. One
 * query, shared by every provider inside CompanyDeadlines (sharing()).
 */
final class FiscalCalendar
{
    /** @var array<int, list<FiscalYear>>|null the saved years per company while CompanyDeadlines computes, so its providers share one query */
    private static ?array $shared = null;

    /**
     * Runs $callback with the company's saved years read once for every
     * call to years() inside it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function sharing(callable $callback): mixed
    {
        $previous = self::$shared;
        self::$shared = [];

        try {
            return $callback();
        } finally {
            self::$shared = $previous;
        }
    }

    /** @return list<FiscalYear> the years that overlap $from–$to, oldest first */
    public static function years(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $saved = self::$shared[$company->id] ?? array_values(CurrentCompany::run($company, fn (): array => FiscalYear::query()->orderBy('start_date')->get(['id', 'company_id', 'start_date', 'end_date', 'status'])->all()));

        if (self::$shared !== null) {
            self::$shared[$company->id] = $saved;
        }

        $calendarYear = FiscalYearRules::requiresCalendarYear($company->legal_form);
        $years = $saved;

        if ($years === []) {
            [$start, $end] = FiscalYearRules::yearContaining($from, $calendarYear ? 1 : $company->fiscal_year_start_month);
            $years[] = self::projected($company, $start, $end);
        }

        while (end($years)->end_date->lessThan($to)) {
            [$start, $end] = FiscalYearRules::nextYear(end($years)->end_date->addDay(), $calendarYear);
            $years[] = self::projected($company, $start, $end);
        }

        return array_values(array_filter($years, fn (FiscalYear $year): bool => $year->end_date->greaterThanOrEqualTo($from) && $year->start_date->lessThanOrEqualTo($to)));
    }

    private static function projected(Company $company, CarbonImmutable $start, CarbonImmutable $end): FiscalYear
    {
        return (new FiscalYear)->forceFill(['company_id' => $company->id, 'start_date' => $start, 'end_date' => $end]);
    }
}
