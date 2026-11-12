<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning;

use App\Models\Company;
use App\Modules\Manufacturing\Domain\DeviationStatus;
use App\Modules\Manufacturing\Domain\DeviationType;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Models\ProductionDeviation;
use App\Modules\Manufacturing\Models\ProductionDeviationImpact;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionPlanForecast;
use App\Modules\Manufacturing\Models\ProductionPlanLock;
use App\Modules\Manufacturing\Models\ProductionPlanRun;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\Engine\PlanForecast;
use App\Modules\Manufacturing\Planning\Engine\PlanResult;
use App\Modules\Manufacturing\Planning\Engine\Scheduler;
use App\Modules\Manufacturing\Planning\Engine\WorkingCalendar;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Computes and stores the plan (ADR-071 §13.4), in one transaction under an
 * advisory lock per company: a snapshot, the scheduler in memory, then the
 * run with its slots and forecasts, compares it with the previous run, gives every deviation
 * waiting for a run its impact on the deliveries, raises LOCK_BROKEN and
 * MATERIAL_SHORTAGE deviations the run itself found, and prunes old runs.
 *
 * simulate() computes a scenario the same way without writing anything
 * (§13.6).
 */
final class ProductionPlanner
{
    /** The first key of the advisory lock that keeps one company's runs apart (ADR-071, §13). */
    public const int LOCK_KEY = 7113;

    public function __construct(private readonly PlanSnapshot $snapshot) {}

    public function run(Company $company, PlanTrigger $trigger, ?CarbonImmutable $now = null): ProductionPlanRun
    {
        return CurrentCompany::run($company, function () use ($company, $trigger, $now): ProductionPlanRun {
            $now = ($now ?? CarbonImmutable::now())->setTimezone(Clock::ZONE)->startOfMinute();

            return DB::transaction(function () use ($company, $trigger, $now): ProductionPlanRun {
                // One plan per company at a time: a second run waits here and then reads what the first wrote.
                DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_KEY, $company->id]);

                $data = $this->snapshot->load($company, $now);
                $started = hrtime(true);
                $result = (new Scheduler)->schedule($data->input);
                $duration = (int) round((hrtime(true) - $started) / 1e6);
                $calendar = PlanningSettings::for($company)->calendar();
                $previous = ProductionPlanRun::query()->latest('id')->lockForUpdate()->first();

                $run = ProductionPlanRun::create([
                    'trigger' => $trigger,
                    'planned_from' => $now,
                    'horizon_until' => Clock::at($data->input->horizonEnd),
                    'order_count' => count($result->forecasts),
                    'slot_count' => count($result->slots),
                    'duration_ms' => $duration,
                ]);

                $this->store($company, $run, $result);
                $this->raise($company, $run, $previous, $result);
                $this->attach($company, $run, $previous, $data, $calendar);
                $this->prune();

                return $run;
            });
        });
    }

    /**
     * A scenario's plan, in memory: the changes as ids, enums, dates and minutes.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    public function simulate(Company $company, array $changes, ?CarbonImmutable $now = null): PlanResult
    {
        return CurrentCompany::run($company, function () use ($company, $changes, $now): PlanResult {
            $now = ($now ?? CarbonImmutable::now())->setTimezone(Clock::ZONE)->startOfMinute();

            return (new Scheduler)->schedule($this->snapshot->load($company, $now, $changes)->input);
        });
    }

    private function store(Company $company, ProductionPlanRun $run, PlanResult $result): void
    {
        $slots = array_map(fn ($slot): array => [
            'company_id' => $company->id,
            'run_id' => $run->id,
            'production_order_id' => $slot->orderId,
            'production_order_operation_id' => $slot->orderOperationId,
            'employee_id' => $slot->employeeId,
            'workstation_id' => $slot->workstationId,
            'starts_at' => Clock::at($slot->start)->toIso8601String(),
            'ends_at' => Clock::at($slot->end)->toIso8601String(),
            'minutes' => $slot->minutes,
            'segments' => json_encode($slot->segments, JSON_THROW_ON_ERROR),
            'locked' => $slot->locked,
            'part' => $slot->part,
        ], $result->slots);

        foreach (array_chunk($slots, 500) as $chunk) {
            DB::table('production_plan_slots')->insert($chunk);
        }

        $forecasts = array_map(fn (PlanForecast $forecast): array => [
            'company_id' => $company->id,
            'run_id' => $run->id,
            'production_order_id' => $forecast->orderId,
            'sales_document_id' => $forecast->salesDocumentId,
            'material_ready_on' => $forecast->materialReadyOn,
            'start_at' => $forecast->startAt === null ? null : Clock::at($forecast->startAt)->toIso8601String(),
            'finish_at' => $forecast->finishAt === null ? null : Clock::at($forecast->finishAt)->toIso8601String(),
            'forecast_delivery_on' => $forecast->deliveryOn,
            'promised_on' => $forecast->promisedOn,
            'status' => $forecast->status->value,
            'waits_for' => $forecast->waitsFor->value,
            'flags' => json_encode($forecast->flags, JSON_THROW_ON_ERROR),
        ], array_values($result->forecasts));

        foreach (array_chunk($forecasts, 500) as $chunk) {
            DB::table('production_plan_forecasts')->insert($chunk);
        }
    }

    /**
     * Deviations the run found itself: a lock that can no longer be kept, and
     * an order whose material date became an assumption.
     */
    private function raise(Company $company, ProductionPlanRun $run, ?ProductionPlanRun $previous, PlanResult $result): void
    {
        $locks = array_values(array_filter($result->brokenLocks, fn (int $id): bool => $id > 0));

        if ($locks !== []) {
            $open = ProductionDeviation::query()->open()->where('type', DeviationType::LockBroken->value)->where('source_type', (new ProductionPlanLock)->getMorphClass())->whereIn('source_id', $locks)->pluck('source_id')->map(fn ($id): int => (int) $id)->all();

            foreach (array_diff($locks, $open) as $lockId) {
                ProductionDeviation::create([
                    'type' => DeviationType::LockBroken,
                    'source_type' => (new ProductionPlanLock)->getMorphClass(),
                    'source_id' => $lockId,
                    'run_before_id' => $previous?->id,
                ]);
            }
        }

        if ($previous === null) {
            return;
        }

        $before = ProductionPlanForecast::query()->where('run_id', $previous->id)->get(['production_order_id', 'flags'])->keyBy('production_order_id');

        foreach ($result->forecasts as $forecast) {
            $earlier = $before->get($forecast->orderId);

            if (! $forecast->has(PlanForecast::ASSUMED_MATERIAL) || $earlier === null || $earlier->hasFlag(PlanForecast::ASSUMED_MATERIAL)) {
                continue;
            }

            ProductionDeviation::create([
                'type' => DeviationType::MaterialShortage,
                'source_type' => (new ProductionOrder)->getMorphClass(),
                'source_id' => $forecast->orderId,
                'run_before_id' => $previous->id,
            ]);
        }
    }

    /**
     * Every deviation still waiting for a run gets this one as its run after,
     * and an impact per production order whose forecast delivery moved. One
     * that moved nothing is closed as NO_IMPACT.
     */
    private function attach(Company $company, ProductionPlanRun $run, ?ProductionPlanRun $previous, PlanData $data, WorkingCalendar $calendar): void
    {
        $pending = ProductionDeviation::query()->whereNull('run_after_id')->where('status', DeviationStatus::Open->value)->orderBy('id')->get();

        if ($pending->isEmpty()) {
            return;
        }

        $after = ProductionPlanForecast::query()->where('run_id', $run->id)->get()->keyBy('production_order_id');
        $beforeRuns = $pending->map(fn (ProductionDeviation $deviation): ?int => $deviation->run_before_id ?? $previous?->id)->filter()->unique()->values()->all();
        $before = ProductionPlanForecast::query()->whereIn('run_id', $beforeRuns)->get()->groupBy('run_id');

        foreach ($pending as $deviation) {
            $baseRun = $deviation->run_before_id ?? $previous?->id;
            $base = $baseRun === null ? collect() : $before->get($baseRun, collect())->keyBy('production_order_id');
            $impacts = [];

            foreach ($after as $orderId => $forecast) {
                $earlier = $base->get($orderId);

                if ($earlier === null) {
                    continue;
                }

                $was = $earlier->forecast_delivery_on?->toDateString();
                $now = $forecast->forecast_delivery_on?->toDateString();

                if ($was === $now) {
                    continue;
                }

                $impacts[] = [
                    'company_id' => $company->id,
                    'deviation_id' => $deviation->id,
                    'production_order_id' => $orderId,
                    'sales_document_id' => $forecast->sales_document_id,
                    'customer_id' => $data->customers[$orderId] ?? null,
                    'promised_on' => $forecast->promised_on?->toDateString(),
                    'forecast_before_on' => $was,
                    'forecast_after_on' => $now,
                    'change_working_days' => $was === null || $now === null ? null : $calendar->workingDaysBetween($was, $now),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($impacts !== []) {
                ProductionDeviationImpact::query()->insert($impacts);
            }

            $deviation->forceFill([
                'run_before_id' => $baseRun,
                'run_after_id' => $run->id,
                ...($impacts === [] ? ['status' => DeviationStatus::Handled, 'resolution' => ProductionDeviation::NO_IMPACT, 'handled_at' => now()] : []),
            ])->save();
        }
    }

    /** Keeps the latest runs, and every run a deviation or a lock refers to. */
    private function prune(): void
    {
        $keep = ProductionPlanRun::query()->latest('id')->limit(ProductionPlanRun::KEEP)->pluck('id')->all();

        ProductionPlanRun::query()
            ->whereNotIn('id', $keep)
            ->whereNotIn('id', ProductionDeviation::query()->whereNotNull('run_before_id')->select('run_before_id'))
            ->whereNotIn('id', ProductionDeviation::query()->whereNotNull('run_after_id')->select('run_after_id'))
            ->whereNotIn('id', ProductionPlanLock::query()->whereNotNull('run_id')->select('run_id'))
            ->delete();
    }
}
