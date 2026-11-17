<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support\Demand;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Planning\Domain\Demand\DayDemand;
use App\Modules\Planning\Domain\Demand\Forecaster;
use App\Modules\Planning\Domain\Demand\HourShape;
use App\Modules\Planning\Domain\Demand\StaffingNeed;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\ForecastOverride;
use App\Modules\Planning\Models\HourProfile;
use App\Modules\Planning\Models\SalesSource;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\StaffingRule;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\SalesFromBooks;
use App\Modules\Planning\Support\ShiftQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Staffing demand for a period and a scope (an area, or the whole company
 * when none), in a fixed number of queries whatever the number of days,
 * shifts, rules or receipts (ADR-037 gap 1, principle 6): the settings, the
 * scope's rules, hour profiles and overrides, the till mappings, the books'
 * daily sales, the tills' hourly sales, and the shifts.
 *
 * Sources by resolution: the day's total is always the books' (per area
 * only when the area has a cost centre, AreaCostCentre); the shape of the
 * day comes from the tills mapped to the scope (or to the whole company),
 * else from the scope's own hour profile, else there is none and demand is
 * per day only.
 */
final class DemandData
{
    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @return array{days: array<string, DayDemand>, area: ?Area, sales_scope: string, weeks: int, till: bool, profiles: bool, rules: Collection<int, StaffingRule>, sales: array<string, int>, lumped: bool}
     */
    public function load(Company $company, CarbonImmutable $from, CarbonImmutable $to, ?Area $area = null): array
    {
        $settings = PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $company));
        $weeks = Forecaster::clampWeeks(PlanningSettings::int($settings, 'forecast_weeks'));
        $today = CarbonImmutable::today(LocalTimestamp::ZONE);
        $from = $from->setTimezone(LocalTimestamp::ZONE)->startOfDay();
        $to = $to->setTimezone(LocalTimestamp::ZONE)->startOfDay();
        $days = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days[] = $day;
        }

        $scope = fn (Builder $query): Builder => $area === null ? $query->whereNull('area_id') : $query->where('area_id', $area->id);

        /** @var Collection<int, StaffingRule> $rules */
        $rules = $scope(StaffingRule::query())->orderBy('valid_from')->orderBy('start_minute')->orderBy('id')->get();
        $profiles = $scope(HourProfile::query())->get()->keyBy('weekday');
        $overrides = [];

        foreach ($scope(ForecastOverride::query())->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get() as $row) {
            if ($row instanceof ForecastOverride) {
                $overrides[$row->date->toDateString()] = ['amount' => $row->amount_ore, 'reason' => $row->reason];
            }
        }
        $tills = $this->tills(SalesSource::query()->get(['pos_connection_id', 'area_id']), $area);

        $costCentre = AreaCostCentre::of($area);
        $lookback = Forecaster::lookbackFrom($from, $today, $weeks);
        $holidays = Forecaster::lastYearHolidays($days);
        $sales = SalesFromBooks::perDay(
            $lookback,
            $to,
            PlanningSettings::int($settings, 'sales_account_from'),
            PlanningSettings::int($settings, 'sales_account_to'),
            $costCentre,
            $holidays,
        );
        $tillSales = TillSales::perHour($tills, $lookback, $to, $holidays);
        $forecasts = Forecaster::forDays($days, $sales, $overrides, $weeks, $today);

        $shifts = ShiftQuery::startingOn($from->subDay(), $to)
            ->where('status', '<>', Shift::CANCELLED)
            ->when($area !== null, fn ($query) => $query->where('area_id', $area?->id))
            ->get(['id', 'employee_id', 'area_id', 'starts_at', 'ends_at'])
            ->groupBy(fn (Shift $shift): string => $shift->starts_at->setTimezone(LocalTimestamp::ZONE)->toDateString());

        $result = [];

        foreach ($days as $day) {
            $date = $day->toDateString();
            $forecast = $forecasts[$date];
            $shape = HourShape::fromTill($tillSales, $forecast->basis)
                ?? HourShape::fromProfile(($profile = $profiles->get($day->isoWeekday())) instanceof HourProfile ? $profile->shares : null);
            $nearby = $shifts->get($day->subDay()->toDateString(), collect())->merge($shifts->get($date, collect()));
            $interval = fn (Shift $shift): array => [$shift->starts_at, $shift->ends_at];

            $result[$date] = StaffingNeed::day(
                $day,
                $forecast,
                $shape,
                $rules,
                array_values($nearby->whereNotNull('employee_id')->map($interval)->all()),
                array_values($nearby->whereNull('employee_id')->map($interval)->all()),
            );
        }

        return [
            'days' => $result,
            'area' => $area,
            'sales_scope' => $costCentre === null ? 'company' : 'area',
            'weeks' => $weeks,
            'till' => $tills !== [],
            'profiles' => $profiles->isNotEmpty(),
            'rules' => $rules,
            'sales' => $sales,
            'lumped' => self::lumped($sales, $lookback, $today),
        ];
    }

    /**
     * Whether the books look booked in lumps (per week or month) rather than
     * per day: fewer than half the weekdays (Monday–Friday, not holidays) of
     * the look-back before today have sales. A forecast per weekday is then
     * not meaningful, and the page says so.
     *
     * @param  array<string, int>  $sales
     */
    public static function lumped(array $sales, CarbonImmutable $from, CarbonImmutable $today): bool
    {
        $weekdays = 0;
        $withSales = 0;

        for ($day = $from; $day->lessThan($today->startOfDay()); $day = $day->addDay()) {
            if ($day->isWeekend() || Forecaster::holidayKey($day) !== null) {
                continue;
            }

            $weekdays++;
            $withSales += ($sales[$day->toDateString()] ?? 0) > 0 ? 1 : 0;
        }

        return $weekdays > 0 && $withSales * 2 < $weekdays;
    }

    /**
     * The tills that give the scope its shape: those mapped to the area, else
     * those mapped to the whole company; for the whole company, those mapped
     * to it, else every mapped till together.
     *
     * @param  Collection<int, SalesSource>  $sources
     * @return list<int>
     */
    private function tills(Collection $sources, ?Area $area): array
    {
        $ids = fn (Collection $rows): array => array_values(array_unique(array_map('intval', $rows->pluck('pos_connection_id')->all())));
        $company = $ids($sources->whereNull('area_id'));

        if ($area !== null) {
            $own = $ids($sources->where('area_id', $area->id));

            return $own !== [] ? $own : $company;
        }

        return $company !== [] ? $company : $ids($sources);
    }
}
