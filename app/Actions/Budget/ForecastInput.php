<?php

declare(strict_types=1);

namespace App\Actions\Budget;

use App\Domain\Budget\BudgetKind;
use App\Domain\Budget\BudgetMonths;
use App\Domain\Budget\ForecastEngine;
use App\Domain\Budget\ForecastMethod;
use App\Domain\Budget\ForecastSettings;
use App\Models\Account;
use App\Models\BudgetVersion;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Reads and checks what a person chose for a forecast: the cut-off month, the
 * methods, the rows they added and the months they typed by hand. Shared by
 * CreateForecast and SaveForecast; must run inside the company
 * (CurrentCompany::run), so every id is looked up in that company only.
 */
final class ForecastInput
{
    /** The most rows a forecast may have their own method on, or add by hand. */
    public const int MAX_ROWS = 2000;

    /**
     * The months that may be a cut-off for the fiscal year: from the month
     * before the year starts (everything forecast) to the year's last month,
     * and only months that have ended.
     *
     * @return list<string> 'Y-m'
     */
    public static function cutoffs(FiscalYear $fiscalYear, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now();
        $months = [BudgetMonths::shift($fiscalYear->start_date->format('Y-m'), -1), ...BudgetMonths::of($fiscalYear)];

        return array_values(array_filter($months, fn (string $month): bool => $month < $today->format('Y-m')));
    }

    /**
     * The cut-off a new forecast starts with: the latest month up to the
     * company's locked period (fully booked), else the latest ended month;
     * null when the year has none yet.
     */
    public static function defaultCutoff(FiscalYear $fiscalYear, ?CarbonImmutable $lockedUntil = null): ?string
    {
        $cutoffs = self::cutoffs($fiscalYear);
        $locked = self::lockedMonth($lockedUntil);
        $inLock = $locked === null ? [] : array_values(array_filter($cutoffs, fn (string $month): bool => $month <= $locked));

        if ($inLock !== []) {
            return $inLock[count($inLock) - 1];
        }

        return $cutoffs === [] ? null : $cutoffs[count($cutoffs) - 1];
    }

    /** The last whole month inside the locked period ('Y-m'), or null without a lock. */
    public static function lockedMonth(?CarbonImmutable $lockedUntil): ?string
    {
        return $lockedUntil?->addDay()->subMonthNoOverflow()->format('Y-m');
    }

    /** Whether the cut-off month is after the locked period, so vouchers may still be added to it. */
    public static function unlocked(string $cutoff, ?CarbonImmutable $lockedUntil): bool
    {
        $locked = self::lockedMonth($lockedUntil);

        return $locked === null || $cutoff > $locked;
    }

    public static function cutoff(FiscalYear $fiscalYear, mixed $input): string
    {
        $month = is_string($input) ? substr(trim($input), 0, 7) : '';

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1 || ! in_array($month, self::cutoffs($fiscalYear), true)) {
            throw ValidationException::withMessages(['cutoff' => __('Välj en brytmånad som har tagit slut: från månaden före räkenskapsåret till årets sista månad.')]);
        }

        return $month;
    }

    /** "oktober 2026": a month as people read it, in the app's language. */
    public static function monthName(string $month): string
    {
        $date = CarbonImmutable::createFromFormat('!Y-m', $month);
        $localized = $date?->locale(app()->getLocale());

        return $localized instanceof CarbonImmutable ? $localized->isoFormat('MMMM YYYY') : $month;
    }

    /** The last day of the cut-off month, as stored in forecast_cutoff. */
    public static function cutoffDate(string $month): string
    {
        return (string) CarbonImmutable::createFromFormat('!Y-m', $month)?->endOfMonth()->toDateString();
    }

    /** @param  array<string, mixed>  $input  revenue_method, cost_method, average_months, growth, row_methods */
    public static function settings(array $input): ForecastSettings
    {
        $revenue = ForecastMethod::tryFrom((string) ($input['revenue_method'] ?? ''));
        $costs = ForecastMethod::tryFrom((string) ($input['cost_method'] ?? ''));

        if ($revenue === null) {
            throw ValidationException::withMessages(['revenue_method' => __('Välj en metod för intäkterna.')]);
        }

        if ($costs === null) {
            throw ValidationException::withMessages(['cost_method' => __('Välj en metod för kostnaderna.')]);
        }

        $months = filter_var($input['average_months'] ?? ForecastSettings::DEFAULT_AVERAGE_MONTHS, FILTER_VALIDATE_INT);

        if ($months === false || $months < 1 || $months > ForecastSettings::MAX_AVERAGE_MONTHS) {
            throw ValidationException::withMessages(['average_months' => __('Snittet räknas på 1–12 månader.')]);
        }

        $growth = BudgetMonths::percent((string) ($input['growth'] ?? ''));

        if ($growth === null || $growth < -10000) {
            throw ValidationException::withMessages(['growth' => __('Skriv förändringen i procent, till exempel 5 eller -2,5.')]);
        }

        $rowMethods = [];
        $given = is_array($input['row_methods'] ?? null) ? $input['row_methods'] : [];

        if (count($given) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['row_methods' => __('Prognosen har för många rader.')]);
        }

        foreach ($given as $key => $method) {
            if ($method === '' || $method === null) {
                continue;
            }

            $case = is_string($method) ? ForecastMethod::tryFrom($method) : null;

            if ($case === null || ! ForecastEngine::validKey((string) $key)) {
                throw ValidationException::withMessages(['row_methods' => __('Välj en metod för raden.')]);
            }

            $rowMethods[(string) $key] = $case;
        }

        return new ForecastSettings($revenue, $costs, $months, $growth, $rowMethods);
    }

    /**
     * Months typed by hand after the cut-off, in ledger sign. Months up to and
     * including the cut-off are skipped: there the outcome applies.
     *
     * @return array<string, array<string, int>>
     */
    public static function overrides(mixed $input, FiscalYear $fiscalYear, string $cutoff): array
    {
        $given = is_array($input) ? $input : [];
        $months = BudgetMonths::of($fiscalYear);
        $overrides = [];
        $cells = 0;

        foreach ($given as $key => $perMonth) {
            if (! ForecastEngine::validKey((string) $key) || ! is_array($perMonth)) {
                throw ValidationException::withMessages(['overrides' => __('En ändrad månad hör inte till någon rad.')]);
            }

            foreach ($perMonth as $month => $amount) {
                if (! is_int($amount) || abs($amount) > BudgetMonths::MAX_CELL_ORE) {
                    throw ValidationException::withMessages(['overrides' => __('Några rutor innehåller inget giltigt belopp (högst 100 miljarder kr per ruta).')]);
                }

                if (! in_array((string) $month, $months, true)) {
                    throw ValidationException::withMessages(['overrides' => __('Månaden :month ingår inte i räkenskapsåret.', ['month' => $month])]);
                }

                // A month up to the cut-off is outcome from the books: a typed amount there is dropped.
                if ((string) $month <= $cutoff) {
                    continue;
                }

                $overrides[(string) $key][(string) $month] = $amount;
                $cells++;
            }
        }

        if ($cells > self::MAX_ROWS * 18) {
            throw ValidationException::withMessages(['overrides' => __('Prognosen har för många rader.')]);
        }

        return $overrides;
    }

    /**
     * Checks that every row key names a result account, cost centre and
     * project of this company. Three queries.
     *
     * @param  list<string>  $keys
     */
    public static function checkRows(array $keys): void
    {
        if (count($keys) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['rows' => __('Prognosen har för många rader.')]);
        }

        $parts = array_map(fn (string $key): array => array_map(intval(...), explode('-', $key)), $keys);
        $accounts = Account::query()->whereIn('id', array_unique(array_column($parts, 0)))->pluck('number', 'id');
        $costCenters = CostCenter::query()->whereIn('id', array_filter(array_unique(array_column($parts, 1))))->pluck('id', 'id');
        $projects = Project::query()->whereIn('id', array_filter(array_unique(array_column($parts, 2))))->pluck('id', 'id');

        foreach ($parts as [$account, $costCenter, $project]) {
            $number = $accounts[$account] ?? null;

            if ($number === null || ! BudgetMonths::isBudgetable((int) $number)) {
                throw ValidationException::withMessages(['rows' => __('Prognosen gäller resultatkonton (3000–8998).')]);
            }

            if ($costCenter !== 0 && ! isset($costCenters[$costCenter])) {
                throw ValidationException::withMessages(['rows' => __('Kostnadsstället finns inte.')]);
            }

            if ($project !== 0 && ! isset($projects[$project])) {
                throw ValidationException::withMessages(['rows' => __('Projektet finns inte.')]);
            }
        }
    }

    /** Refuses the budget method when the fiscal year has no budget the reports use. */
    public static function checkBudget(ForecastSettings $settings, FiscalYear $fiscalYear): void
    {
        if (! $settings->usesBudget()) {
            return;
        }

        $exists = BudgetVersion::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('kind', BudgetKind::Budget->value)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['revenue_method' => __('Räkenskapsåret har ingen budget som används i rapporterna. Välj en annan metod, eller gör en budget först.')]);
        }
    }
}
