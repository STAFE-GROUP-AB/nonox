<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning;

use App\Models\Company;
use App\Modules\Manufacturing\Models\ProductionPlanForecast;
use App\Modules\Manufacturing\Models\ProductionPlanRun;
use App\Modules\Manufacturing\Models\ProductionPlanSlot;
use App\Modules\Manufacturing\Models\Workstation;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The read side of the plan (ADR-071 §13.1), for the calendar's screens and
 * for the workshop wall screen (M7): the latest run, its forecasts with
 * their orders and customers, its slots in a period with person, workstation
 * and operation, and the load per person and workstation per day. Every
 * method runs a fixed number of queries, whatever the number of orders.
 *
 * Absence is only ever "Frånvarande": the board knows the dates a person is
 * away, never why.
 */
final class PlanBoard
{
    public function __construct(private readonly PlanSnapshot $snapshot) {}

    public function latestRun(Company $company): ?ProductionPlanRun
    {
        return CurrentCompany::run($company, fn (): ?ProductionPlanRun => ProductionPlanRun::query()->latest('id')->first());
    }

    /** The latest run's id, the cheap question the screens poll. */
    public function latestRunId(Company $company): ?int
    {
        return CurrentCompany::run($company, fn (): ?int => ($id = ProductionPlanRun::query()->max('id')) === null ? null : (int) $id);
    }

    /** @return Collection<int, ProductionPlanForecast> */
    public function forecasts(Company $company, ProductionPlanRun $run): Collection
    {
        return CurrentCompany::run($company, fn (): Collection => ProductionPlanForecast::query()
            ->where('run_id', $run->id)
            ->with(['order.article:id,number,name', 'salesDocument:id,number,customer_id,kind', 'salesDocument.customer:id,name'])
            ->get()
            ->sortBy(fn (ProductionPlanForecast $forecast): array => [$forecast->status->rank(), $forecast->promised_on?->toDateString() ?? '9999-12-31', $forecast->production_order_id])
            ->values());
    }

    /** @return Collection<int, ProductionPlanSlot> slots with any working time in [from, to) */
    public function slots(Company $company, ProductionPlanRun $run, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return CurrentCompany::run($company, fn (): Collection => ProductionPlanSlot::query()
            ->where('run_id', $run->id)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->with(['order:id,number,name,article_id,sales_document_id', 'orderOperation:id,operation_id,position', 'orderOperation.operation:id,name', 'employee:id,first_name,last_name', 'workstation:id,name'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get());
    }

    /**
     * Per person and per workstation, per day from $from for $days days: the
     * minutes of working time, the minutes booked, and whether the person is
     * away that day.
     *
     * @return array{
     *     days: list<string>,
     *     people: list<array{id: int, name: string, days: array<string, array{available: int, booked: int, absent: bool}>}>,
     *     workstations: list<array{id: int, name: string, days: array<string, array{available: int, booked: int, absent: bool}>}>
     * }
     */
    public function load(Company $company, ProductionPlanRun $run, CarbonImmutable $from, int $days): array
    {
        return CurrentCompany::run($company, function () use ($company, $run, $from, $days): array {
            $from = $from->setTimezone(Clock::ZONE)->startOfDay();
            $to = $from->addDays($days);
            $dates = [];

            for ($day = $from; $day->lessThan($to); $day = $day->addDay()) {
                $dates[] = $day->toDateString();
            }

            [$people, $absent] = $this->snapshot->people($company, PlanningSettings::for($company), $from, $to->subSecond());
            $stations = $this->snapshot->workstations($company, $from, $to);
            $names = Employee::query()->whereIn('id', array_keys($people))->get(['id', 'first_name', 'last_name'])->keyBy('id');
            $stationNames = Workstation::query()->whereIn('id', array_keys($stations))->pluck('name', 'id');
            $slots = ProductionPlanSlot::query()->where('run_id', $run->id)->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get(['employee_id', 'workstation_id', 'segments']);

            $booked = ['people' => [], 'workstations' => []];

            foreach ($slots as $slot) {
                foreach ($slot->segments as [$start, $end]) {
                    $date = Clock::date((int) $start);

                    if ($slot->employee_id !== null) {
                        $booked['people'][$slot->employee_id][$date] = ($booked['people'][$slot->employee_id][$date] ?? 0) + (int) $end - (int) $start;
                    }

                    if ($slot->workstation_id !== null) {
                        $booked['workstations'][$slot->workstation_id][$date] = ($booked['workstations'][$slot->workstation_id][$date] ?? 0) + (int) $end - (int) $start;
                    }
                }
            }

            $perDay = function (array $intervals, array $bookedDays, array $absentDays) use ($dates): array {
                $result = [];

                foreach ($dates as $date) {
                    $start = Clock::local($date, 0);
                    $end = Clock::local(CarbonImmutable::parse($date)->addDay()->toDateString(), 0);
                    $available = 0;

                    foreach ($intervals as [$a, $b]) {
                        $available += max(0, (int) min($b, $end) - (int) max($a, $start));
                    }

                    $result[$date] = ['available' => $available, 'booked' => (int) ($bookedDays[$date] ?? 0), 'absent' => in_array($date, $absentDays, true)];
                }

                return $result;
            };

            $rows = [];

            foreach ($people as $id => $person) {
                $employee = $names->get($id);
                $rows[] = ['id' => $id, 'name' => $employee === null ? '' : trim("{$employee->first_name} {$employee->last_name}"), 'days' => $perDay($person->intervals, $booked['people'][$id] ?? [], $absent[$id] ?? [])];
            }

            $stationRows = [];

            foreach ($stations as $id => $intervals) {
                $stationRows[] = ['id' => $id, 'name' => (string) $stationNames->get($id), 'days' => $perDay($intervals, $booked['workstations'][$id] ?? [], [])];
            }

            return ['days' => $dates, 'people' => $rows, 'workstations' => $stationRows];
        });
    }
}
