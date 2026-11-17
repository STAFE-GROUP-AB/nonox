<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support\Budget;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Planning\Domain\Budget\BudgetLine;
use App\Modules\Planning\Domain\Budget\BudgetWeek;
use App\Modules\Planning\Domain\Demand\Forecaster;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Budget;
use App\Modules\Planning\Models\ForecastOverride;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\SalesFromBooks;
use App\Modules\Planning\Support\ShiftQuery;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The staff-cost budget per week against the estimated cost of the planned
 * shifts (ADR-037 gap 2), for one company or several (the chain view), in a
 * fixed number of queries whatever the number of weeks, areas, shifts or
 * companies: the budgets; the shifts (unless the caller already has them);
 * and, only when a budget is a share of the forecast, the areas' cost
 * centres, the forecast overrides and the books' daily sales.
 *
 * The forecast is Bemanningsbehov's (gap 1), recomputed from the same
 * inputs: an area's own sales when it has a cost centre, else the whole
 * company's, with the area's own overrides. The cost is the sum of the
 * shifts' cost_estimate_ore, a shift counting in the week (Monday–Sunday,
 * Swedish time) it starts.
 *
 * Across companies the caller runs this inside CompanyScope::bypass with ids
 * from the user's own memberships; every query names the companies.
 */
final class BudgetData
{
    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @param  list<CarbonImmutable>  $mondays
     * @return array<string, BudgetWeek> Y-m-d of the Monday => week
     */
    public function forCompany(Company $company, array $mondays): array
    {
        $settings = PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $company));

        return CurrentCompany::run($company, fn (): array => $this->forCompanies([(int) $company->id], $mondays, [(int) $company->id => $settings])[(int) $company->id] ?? []);
    }

    /**
     * @param  list<int>  $companyIds
     * @param  list<CarbonImmutable>  $mondays
     * @param  array<int, array<string, mixed>>  $settings  resolved planning settings by company
     * @param  ?array<int, array<string, array<int|string, array{cost: int, incomplete: bool, days: array<string, int>}>>>  $costs  from self::costs(), when the caller has read the shifts
     * @return array<int, array<string, BudgetWeek>> company id => Monday => week
     */
    public function forCompanies(array $companyIds, array $mondays, array $settings, ?array $costs = null): array
    {
        if ($companyIds === [] || $mondays === []) {
            return [];
        }

        $mondays = array_map(fn (CarbonImmutable $day): CarbonImmutable => $day->setTimezone(LocalTimestamp::ZONE)->startOfDay()->startOfWeek(CarbonImmutable::MONDAY), $mondays);
        usort($mondays, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->getTimestamp() <=> $b->getTimestamp());
        $first = $mondays[0];
        $last = $mondays[count($mondays) - 1]->addDays(6);
        $dates = array_values(array_unique(array_map(fn (CarbonImmutable $day): string => $day->toDateString(), $mondays)));

        // A budget on an archived area cannot be reached on the budget page, so it does not count (subquery, no extra query).
        /** @var Collection<int, Budget> $budgets */
        $budgets = Budget::query()
            ->whereIn('company_id', $companyIds)
            ->whereIn('week_start', $dates)
            ->where(fn ($query) => $query->whereNull('area_id')->orWhereIn('area_id', DB::table('planning_areas')->select('id')->whereIn('company_id', $companyIds)->whereNull('archived_at')))
            ->get();

        $costs ??= self::costs(ShiftQuery::startingOn($first, $last)
            ->whereIn('company_id', $companyIds)
            ->where('status', '<>', Shift::CANCELLED)
            ->get(['id', 'company_id', 'employee_id', 'area_id', 'starts_at', 'cost_estimate_ore', 'cost_details']));

        $forecasts = $this->forecasts($budgets->filter(fn (Budget $budget): bool => $budget->isPercent()), $settings);
        $result = [];

        foreach ($companyIds as $companyId) {
            $warnAt = PlanningSettings::budgetWarnAt($settings[$companyId] ?? []);

            foreach ($dates as $monday) {
                $weekCosts = $costs[$companyId][$monday] ?? [];
                $company = null;
                $areas = [];

                foreach ($budgets->where('company_id', $companyId)->filter(fn (Budget $budget): bool => $budget->week_start->toDateString() === $monday) as $budget) {
                    $forecast = $forecasts[$budget->id] ?? ['amount' => null, 'dates' => []];
                    $scoped = $budget->area_id === null ? $weekCosts : array_intersect_key($weekCosts, [$budget->area_id => true]);
                    $perDay = self::perDay($scoped);
                    $percent = $budget->isPercent();
                    // A share of the forecast is compared with the cost of the days that have a forecast; the shifts on other days are named apart.
                    $uncovered = $percent ? array_diff_key($perDay, array_flip($forecast['dates'])) : [];
                    $uncovered = array_filter($uncovered, fn (int $cost): bool => $cost > 0);
                    $line = new BudgetLine(
                        $budget->area_id,
                        $percent ? BudgetLine::PERCENT : BudgetLine::AMOUNT,
                        $percent ? BudgetLine::shareOf($forecast['amount'], (int) $budget->percent_bp) : $budget->amount_ore,
                        $budget->percent_bp,
                        $percent ? $forecast['amount'] : null,
                        $percent ? count($forecast['dates']) : 0,
                        array_sum(array_column($scoped, 'cost')) - array_sum($uncovered),
                        in_array(true, array_column($scoped, 'incomplete'), true),
                        $warnAt,
                        forecastDates: $percent ? $forecast['dates'] : null,
                        uncoveredDays: count($uncovered),
                        uncoveredOre: array_sum($uncovered),
                    );

                    if ($budget->area_id === null) {
                        $company = $line;
                    } else {
                        $areas[$budget->area_id] = $line;
                    }
                }

                ksort($areas);
                $result[$companyId][$monday] = new BudgetWeek($monday, $company, $areas, $warnAt);
            }
        }

        return $result;
    }

    /**
     * The estimated cost per company, week and area ('none' without one),
     * also per day, and whether part of it is missing, from shifts already
     * read. A shift
     * counts in the week it starts, Swedish time.
     *
     * @param  Collection<int, Shift>  $shifts
     * @return array<int, array<string, array<int|string, array{cost: int, incomplete: bool, days: array<string, int>}>>>
     */
    public static function costs(Collection $shifts): array
    {
        $costs = [];

        foreach ($shifts as $shift) {
            if ($shift->status === Shift::CANCELLED) {
                continue;
            }

            $monday = $shift->starts_at->setTimezone(LocalTimestamp::ZONE)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            $area = $shift->area_id ?? 'none';
            $entry = $costs[(int) $shift->company_id][$monday][$area] ?? ['cost' => 0, 'incomplete' => false, 'days' => []];
            $date = $shift->starts_at->setTimezone(LocalTimestamp::ZONE)->toDateString();
            $entry['cost'] += $shift->cost_estimate_ore ?? 0;
            $entry['days'][$date] = ($entry['days'][$date] ?? 0) + ($shift->cost_estimate_ore ?? 0);
            $entry['incomplete'] = $entry['incomplete'] || ($shift->employee_id !== null && ($shift->cost_estimate_ore === null || ((array) ($shift->cost_details['missing'] ?? [])) !== []));
            $costs[(int) $shift->company_id][$monday][$area] = $entry;
        }

        return $costs;
    }

    /**
     * The week's forecast sales for each budget that is a share of it: the
     * sum of the days that have a forecast, and which days those are. Three queries
     * for all the budgets together, none when there are no such budgets.
     *
     * @param  Collection<int, Budget>  $budgets
     * @param  array<int, array<string, mixed>>  $settings
     * @return array<int, array{amount: ?int, dates: list<string>}> budget id => forecast
     */
    private function forecasts(Collection $budgets, array $settings): array
    {
        if ($budgets->isEmpty()) {
            return [];
        }

        $companyIds = array_values(array_unique(array_map('intval', $budgets->pluck('company_id')->all())));
        $today = CarbonImmutable::today(LocalTimestamp::ZONE);
        $mondays = $budgets->map(fn (Budget $budget): CarbonImmutable => CarbonImmutable::parse($budget->week_start->toDateString(), LocalTimestamp::ZONE));
        $first = $mondays->sortBy(fn (CarbonImmutable $day): int => $day->getTimestamp())->first() ?? $today;
        $last = ($mondays->sortByDesc(fn (CarbonImmutable $day): int => $day->getTimestamp())->first() ?? $today)->addDays(6);
        $allDays = [];

        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $allDays[] = $day;
        }

        $accounts = [];
        $weeks = [];
        $lookback = $first;

        foreach ($companyIds as $companyId) {
            $companySettings = $settings[$companyId] ?? PlanningSettings::DEFAULTS;
            $accounts[$companyId] = [PlanningSettings::int($companySettings, 'sales_account_from'), PlanningSettings::int($companySettings, 'sales_account_to')];
            $weeks[$companyId] = Forecaster::clampWeeks(PlanningSettings::int($companySettings, 'forecast_weeks'));
            $lookback = $lookback->min(Forecaster::lookbackFrom($first, $today, $weeks[$companyId]));
        }

        $areaIds = array_values(array_unique(array_map('intval', $budgets->pluck('area_id')->filter()->all())));
        $centres = $areaIds === [] ? collect() : Area::query()->whereIn('company_id', $companyIds)->whereIn('id', $areaIds)->pluck('cost_center_id', 'id');
        $overrides = [];

        foreach (ForecastOverride::query()->whereIn('company_id', $companyIds)->whereBetween('date', [$first->toDateString(), $last->toDateString()])->get(['company_id', 'area_id', 'date', 'amount_ore', 'reason']) as $row) {
            $overrides[(int) $row->company_id][(string) ($row->area_id ?? 'company')][$row->date->toDateString()] = ['amount' => $row->amount_ore, 'reason' => $row->reason];
        }

        $sales = SalesFromBooks::perCompanyDay($accounts, $lookback, $last, Forecaster::lastYearHolidays($allDays));
        $result = [];

        foreach ($budgets as $budget) {
            $companyId = (int) $budget->company_id;
            $centre = $budget->area_id === null ? null : $centres->get($budget->area_id);
            $scopeSales = is_numeric($centre) ? ($sales[$companyId]['centres'][(int) $centre] ?? []) : ($sales[$companyId]['all'] ?? []);
            $monday = CarbonImmutable::parse($budget->week_start->toDateString(), LocalTimestamp::ZONE);
            $days = [];

            for ($offset = 0; $offset < 7; $offset++) {
                $days[] = $monday->addDays($offset);
            }

            $amount = null;
            $withForecast = [];

            foreach (Forecaster::forDays($days, $scopeSales, $overrides[$companyId][(string) ($budget->area_id ?? 'company')] ?? [], $weeks[$companyId], $today) as $forecast) {
                if ($forecast->amountOre === null) {
                    continue;
                }

                $amount = ($amount ?? 0) + $forecast->amountOre;
                $withForecast[] = $forecast->date;
            }

            $result[$budget->id] = ['amount' => $amount, 'dates' => $withForecast];
        }

        return $result;
    }

    /**
     * The cost per day of the given areas together.
     *
     * @param  array<int|string, array{cost: int, incomplete: bool, days: array<string, int>}>  $costs
     * @return array<string, int>
     */
    private static function perDay(array $costs): array
    {
        $days = [];

        foreach ($costs as $entry) {
            foreach ($entry['days'] as $date => $cost) {
                $days[$date] = ($days[$date] ?? 0) + $cost;
            }
        }

        return $days;
    }
}
