<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support\Staffing;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\SalesFromBooks;
use App\Modules\Planning\Support\ShiftCoster;
use App\Modules\Planning\Support\ShiftQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Bemanning och kostnad (PLANNING_MODULE.md §7): per day and per week of a
 * period, the planned hours and estimated labour cost from the schedule,
 * the actual hours and their estimated cost (attested time where it exists,
 * otherwise the staff ledger), the sales in the books on the company's
 * revenue accounts, labour cost as a share of sales, and the same weeks last
 * year (52 weeks back, so weekdays line up) when the books have them.
 *
 * Every cost is an estimate (ShiftCost): payroll owns pay. Sales are the
 * whole company's, also when one area is chosen. A fixed number of queries
 * whatever the number of days, people and shifts.
 */
final class StaffingReport
{
    public const int MAX_DAYS = 93;

    public const int LAST_YEAR_WEEKS = 52;

    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ShiftCoster $coster,
        private readonly AttestedActualTime $attested,
        private readonly LedgerActualTime $ledger,
    ) {}

    /** @return array<string, mixed> */
    public function load(Company $company, CarbonImmutable $from, CarbonImmutable $to, ?int $areaId = null): array
    {
        [$from, $to] = self::period($from, $to);
        $settings = PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $company));
        $accountFrom = PlanningSettings::int($settings, 'sales_account_from');
        $accountTo = PlanningSettings::int($settings, 'sales_account_to');
        $back = fn (CarbonImmutable $day): CarbonImmutable => $day->subWeeks(self::LAST_YEAR_WEEKS);

        $areas = Area::query()->orderBy('sort')->orderBy('name')->get();
        $area = $areaId === null ? null : $areas->firstWhere('id', $areaId);
        $areaId = $area?->id;

        $planned = $this->planned($from, $to, $areaId);
        $plannedLastYear = $this->planned($back($from), $back($to), $areaId);
        $sales = SalesFromBooks::perDay($from, $to, $accountFrom, $accountTo);
        $salesLastYear = SalesFromBooks::perDay($back($from), $back($to), $accountFrom, $accountTo);
        [$actual, $actualNotes] = $this->actual($company, $from, $to, $area);

        $days = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $lastYear = $back($day)->toDateString();
            $plan = $planned[$date] ?? self::empty();
            $was = $actual[$date] ?? null;
            $planLastYear = $plannedLastYear[$lastYear] ?? self::empty();

            $days[] = [
                'date' => $date,
                'week' => $day->isoWeekYear().'-'.str_pad((string) $day->isoWeek(), 2, '0', STR_PAD_LEFT),
                'planned_minutes' => $plan['minutes'],
                'planned_cost' => $plan['cost'],
                'planned_incomplete' => $plan['incomplete'],
                'actual_minutes' => $was['minutes'] ?? null,
                'actual_cost' => $was['cost'] ?? null,
                'actual_incomplete' => $was['incomplete'] ?? false,
                'actual_source' => $was['source'] ?? null,
                'sales' => $sales[$date] ?? null,
                'last_year_date' => $lastYear,
                'last_year_sales' => $salesLastYear[$lastYear] ?? null,
                'last_year_planned_minutes' => $planLastYear['minutes'],
                'last_year_planned_cost' => $planLastYear['cost'],
            ];
        }

        $days = array_map(fn (array $row): array => self::withPercents($row), $days);
        $byWeek = [];
        $weeks = [];

        foreach ($days as $row) {
            $byWeek[$row['week']][] = $row;
        }

        foreach ($byWeek as $week => $rows) {
            $weeks[] = self::withPercents([
                'week' => $week,
                'number' => (int) substr($week, 5),
                'from' => $rows[0]['date'],
                'to' => $rows[array_key_last($rows)]['date'],
                ...self::sum(collect($rows)),
            ]);
        }

        return [
            'from' => $from,
            'to' => $to,
            'area' => $area,
            'areas' => $areas,
            'accounts' => [$accountFrom, $accountTo],
            'days' => $days,
            'weeks' => $weeks,
            'totals' => self::withPercents(self::sum(collect($days))),
            'has_actual' => collect($days)->contains(fn (array $row): bool => $row['actual_minutes'] !== null),
            'has_last_year' => $salesLastYear !== [] || $plannedLastYear !== [],
            'actual_sources' => collect($days)->pluck('actual_source')->filter()->unique()->values()->all(),
            'notes' => $actualNotes,
        ];
    }

    /**
     * The period as whole days in Sweden, in order and at most MAX_DAYS long.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public static function period(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->setTimezone(LocalTimestamp::ZONE)->startOfDay();
        $to = $to->setTimezone(LocalTimestamp::ZONE)->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to->min($from->addDays(self::MAX_DAYS - 1))];
    }

    /**
     * Planned minutes and the stored cost estimates per day. Open shifts
     * count in the hours but have no cost until someone takes them.
     *
     * @return array<string, array{minutes: int, cost: int, incomplete: bool}>
     */
    private function planned(CarbonImmutable $from, CarbonImmutable $to, ?int $areaId): array
    {
        $shifts = ShiftQuery::startingOn($from, $to)
            ->where('status', '<>', Shift::CANCELLED)
            ->when($areaId !== null, fn ($query) => $query->where('area_id', $areaId))
            ->get(['id', 'employee_id', 'area_id', 'starts_at', 'ends_at', 'break_minutes', 'cost_estimate_ore', 'cost_details']);

        return $shifts->groupBy(fn (Shift $shift): string => $shift->starts_at->toDateString())
            ->map(fn (Collection $day): array => [
                'minutes' => (int) $day->sum(fn (Shift $shift): int => $shift->workedMinutes()),
                'cost' => (int) $day->sum(fn (Shift $shift): int => $shift->cost_estimate_ore ?? 0),
                'incomplete' => $day->contains(fn (Shift $shift): bool => $shift->employee_id !== null && ($shift->cost_estimate_ore === null || ((array) ($shift->cost_details['missing'] ?? [])) !== [])),
            ])
            ->all();
    }

    /**
     * Actual minutes and their estimated cost per day: attested time on the
     * days that have it, the staff ledger on the others. Costs are estimated
     * like planned shifts, on the real start and end.
     *
     * @return array{array<string, array{minutes: int, cost: int, incomplete: bool, source: string}>, list<string>}
     */
    private function actual(Company $company, CarbonImmutable $from, CarbonImmutable $to, ?Area $area): array
    {
        $notes = [];
        $byDay = [];
        $open = 0;

        foreach ([$this->attested, $this->ledger] as $source) {
            $period = $source->between($company, $from, $to);

            if ($period === null) {
                continue;
            }

            if ($area !== null && $source->key() === 'ledger' && $area->location_id === null) {
                $notes[] = __('Personalliggaren vet inte vilken avdelning någon arbetade på. Koppla avdelningen till en arbetsplats under Inställningar för att se faktisk tid per avdelning.');

                continue;
            }

            $work = collect($period->work)->filter(fn (ActualWork $work): bool => $area === null
                || ($work->areaId !== null ? $work->areaId === $area->id : ($source->key() === 'ledger' && $work->locationId === $area->location_id)));
            $grouped = $work->groupBy('day');

            foreach ($period->days as $day) {
                if (isset($byDay[$day])) {
                    continue;
                }

                $byDay[$day] = ['source' => $source->key(), 'work' => $grouped->get($day, collect())->values()->all()];
            }

            if ($source->key() === 'ledger') {
                $open += $period->openShifts;
            }
        }

        $costs = $this->costs($company, collect($byDay)->flatMap(fn (array $day): array => $day['work'])->values());
        $result = [];
        $index = 0;

        foreach ($byDay as $day => $one) {
            $minutes = 0;
            $cost = 0;
            $incomplete = false;

            foreach ($one['work'] as $work) {
                $minutes += $work->minutes;
                $estimate = $costs[$index++] ?? null;
                $cost += $estimate ?? 0;
                $incomplete = $incomplete || $estimate === null;
            }

            $result[$day] = ['minutes' => $minutes, 'cost' => $cost, 'incomplete' => $incomplete, 'source' => $one['source']];
        }

        if ($open > 0) {
            $notes[] = trans_choice('{1} 1 incheckning i personalliggaren saknar utcheckning och räknas inte.|[2,*] :count incheckningar i personalliggaren saknar utcheckning och räknas inte.', $open, ['count' => $open]);
        }

        return [$result, $notes];
    }

    /**
     * The estimated cost of each piece of actual work, in the same order:
     * through ShiftCoster on an unsaved shift at the real times, so the
     * estimate follows the same rules as the plan. Null when the employee or
     * the times are unknown, or the wage is missing.
     *
     * @param  Collection<int, ActualWork>  $work
     * @return list<?int>
     */
    private function costs(Company $company, Collection $work): array
    {
        $shifts = $work->map(function (ActualWork $work): ?Shift {
            if ($work->employeeId === null || $work->start === null || $work->end === null) {
                return null;
            }

            $shift = new Shift;
            $shift->forceFill([
                'employee_id' => $work->employeeId,
                'starts_at' => $work->start,
                'ends_at' => $work->end,
                'break_minutes' => $work->breakMinutes,
            ]);

            return $shift;
        });

        $this->coster->apply($company, $shifts->filter()->values());

        return array_values($shifts->map(fn (?Shift $shift): ?int => $shift?->cost_estimate_ore)->all());
    }

    /** @return array{minutes: int, cost: int, incomplete: bool} */
    private static function empty(): array
    {
        return ['minutes' => 0, 'cost' => 0, 'incomplete' => false];
    }

    /**
     * Sums of day rows. Actual and sales stay null when no day has them;
     * last year likewise.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function sum(Collection $rows): array
    {
        $nullable = fn (string $key): ?int => $rows->contains(fn (array $row): bool => $row[$key] !== null) ? (int) $rows->sum($key) : null;

        return [
            'planned_minutes' => (int) $rows->sum('planned_minutes'),
            'planned_cost' => (int) $rows->sum('planned_cost'),
            'planned_incomplete' => $rows->contains('planned_incomplete', true),
            'actual_minutes' => $nullable('actual_minutes'),
            'actual_cost' => $nullable('actual_cost'),
            'actual_incomplete' => $rows->contains('actual_incomplete', true),
            'sales' => $nullable('sales'),
            'last_year_sales' => $nullable('last_year_sales'),
            'last_year_planned_minutes' => (int) $rows->sum('last_year_planned_minutes'),
            'last_year_planned_cost' => (int) $rows->sum('last_year_planned_cost'),
            // The planned cost of the days that also have actual cost, so the two shares compare like with like.
            'actual_days_sales' => $rows->contains(fn (array $row): bool => $row['actual_cost'] !== null && $row['sales'] !== null)
                ? (int) $rows->filter(fn (array $row): bool => $row['actual_cost'] !== null)->sum(fn (array $row): int => (int) ($row['sales'] ?? 0))
                : null,
        ];
    }

    /**
     * Labour cost as a share of sales, one decimal: planned against the
     * sales of the period, actual against the sales of the days with actual
     * time, last year's planned against last year's sales.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function withPercents(array $row): array
    {
        $share = fn (?int $cost, ?int $sales): ?float => $cost !== null && $cost > 0 && $sales !== null && $sales > 0 ? round($cost * 100 / $sales, 1) : null;
        $actualSales = array_key_exists('actual_days_sales', $row) ? $row['actual_days_sales'] : ($row['actual_cost'] === null ? null : $row['sales']);

        return [
            ...$row,
            'planned_percent' => $share($row['planned_cost'], $row['sales']),
            'actual_percent' => $share($row['actual_cost'], $actualSales),
            'last_year_percent' => $share($row['last_year_planned_cost'], $row['last_year_sales']),
        ];
    }
}
