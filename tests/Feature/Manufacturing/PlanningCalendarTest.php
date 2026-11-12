<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\Purchasing\SendPurchaseOrder;
use App\Modules\Manufacturing\Domain\DeviationStatus;
use App\Modules\Manufacturing\Domain\DeviationType;
use App\Modules\Manufacturing\Domain\ForecastStatus;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\Domain\WaitsFor;
use App\Modules\Manufacturing\Models\ProductionDeviation;
use App\Modules\Manufacturing\Models\ProductionOrderComponent;
use App\Modules\Manufacturing\Models\ProductionPlanForecast;
use App\Modules\Manufacturing\Models\ProductionPlanRun;
use App\Modules\Manufacturing\Models\ProductionPlanSlot;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\PlanSnapshot;
use App\Modules\Manufacturing\Planning\ProductionPlanner;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Manufacturing;
use Tests\Support\Planning;
use Tests\Support\PlanningWorkshop;
use Tests\Support\Purchasing;

/*
 * ADR-071 §13.3–13.5 on the database: the snapshot of the §13.9 workshop
 * gives the golden plan, the material date comes from a sent purchase
 * order, absence becomes a deviation with its impact, and runs are pruned.
 */

beforeEach(function () {
    ['owner' => $this->owner, 'company' => $this->company] = Manufacturing::company();
    $this->now = CarbonImmutable::parse('2026-11-16 07:00', Clock::ZONE);
    $this->travelTo($this->now);
});

function forecastsOf(ProductionPlanRun $run): array
{
    return ProductionPlanForecast::query()->where('run_id', $run->id)->with('order:id,number')->get()
        ->mapWithKeys(fn (ProductionPlanForecast $forecast): array => [$forecast->order->number => [$forecast->forecast_delivery_on?->toDateString(), $forecast->status]])
        ->all();
}

it('plans the fixture workshop from the database as F-P0 says, the material date from a sent purchase order', function () {
    $shop = PlanningWorkshop::build($this->company, $this->owner);

    // TO-1043 needs pallet boards that come with a purchase order on 2026-11-24.
    $supplier = Purchasing::supplier($this->owner, $this->company);
    $board = Purchasing::screw($this->owner, $this->company, $supplier, ['number' => 'BRADA', 'name' => 'Pallbräda']);
    CurrentCompany::run($this->company, fn () => ProductionOrderComponent::create([
        'production_order_id' => $shop['orders'][1043]->id, 'position' => 0, 'component_article_id' => $board->id,
        'quantity_milli' => 5000, 'required_milli' => 100_000,
    ]));
    $order = Purchasing::order($this->owner, $this->company, $supplier, [['article_id' => $board->id, 'quantity' => '100', 'price' => '1', 'expected_on' => '2026-11-24']]);
    app(SendPurchaseOrder::class)->printed($this->owner, $this->company, $order);

    $run = app(ProductionPlanner::class)->run($this->company, PlanTrigger::Manual, $this->now);

    expect(forecastsOf($run))->toEqual([
        1042 => ['2026-11-20', ForecastStatus::Green],
        1041 => ['2026-11-23', ForecastStatus::Green],
        1043 => ['2026-11-27', ForecastStatus::Green],
    ]);

    $pall = ProductionPlanForecast::query()->where('run_id', $run->id)->where('production_order_id', $shop['orders'][1043]->id)->sole();
    expect($pall->waits_for)->toBe(WaitsFor::Material)
        ->and($pall->material_ready_on?->toDateString())->toBe('2026-11-24')
        ->and($run->slot_count)->toBe(14);
});

it('turns an approved absence into a deviation with its impact, and never reads its type', function () {
    $shop = PlanningWorkshop::build($this->company, $this->owner);
    $planner = app(ProductionPlanner::class);
    $planner->run($this->company, PlanTrigger::Manual, $this->now);

    DB::enableQueryLog();

    // Approving the absence raises the deviation; the queued plan (sync in tests) attaches the impact.
    CurrentCompany::run($this->company, fn () => Absence::create([
        'employee_id' => $shop['people']['tove']->id, 'type' => 'SICK', 'date_from' => '2026-11-17', 'date_to' => '2026-11-23', 'status' => 'APPROVED',
    ]));

    $absenceQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'from "absences"'));
    expect($absenceQueries)->not->toBeEmpty()
        ->and($absenceQueries->every(fn (string $sql): bool => ! str_contains($sql, '"type"') && ! str_contains($sql, '"note"') && ! str_contains($sql, '*')))->toBeTrue();

    $deviation = CurrentCompany::run($this->company, fn () => ProductionDeviation::query()->where('type', DeviationType::Absence->value)->sole());
    $impacts = $deviation->impacts()->with('order:id,number')->get()->mapWithKeys(fn ($impact): array => [$impact->order->number => [$impact->forecast_before_on?->toDateString(), $impact->forecast_after_on?->toDateString(), $impact->change_working_days]])->all();

    expect($deviation->status)->toBe(DeviationStatus::Open)
        ->and($deviation->employee_id)->toBe($shop['people']['tove']->id)
        ->and($deviation->details)->toBe([])
        ->and($deviation->source_type)->toBeNull()
        ->and(array_intersect_key($impacts, [1042 => true, 1041 => true]))->toEqual([
            1042 => ['2026-11-20', '2026-11-25', 3],
            1041 => ['2026-11-23', '2026-11-26', 3],
        ]);
});

it('closes a deviation that moved no delivery, and keeps only the latest runs', function () {
    PlanningWorkshop::build($this->company, $this->owner);
    $planner = app(ProductionPlanner::class);

    foreach (range(1, ProductionPlanRun::KEEP + 3) as $ignored) {
        $planner->run($this->company, PlanTrigger::Manual, $this->now);
    }

    CurrentCompany::run($this->company, function () {
        expect(ProductionPlanRun::query()->count())->toBe(ProductionPlanRun::KEEP);
    });

    // An absence on a Sunday changes nothing.
    $shop = CurrentCompany::run($this->company, fn () => Employee::query()->orderBy('id')->first());
    CurrentCompany::run($this->company, fn () => Absence::create(['employee_id' => $shop->id, 'type' => 'VACATION', 'date_from' => '2026-11-22', 'date_to' => '2026-11-22', 'status' => 'APPROVED']));

    $deviation = CurrentCompany::run($this->company, fn () => ProductionDeviation::query()->sole());
    expect($deviation->status)->toBe(DeviationStatus::Handled)
        ->and($deviation->resolution)->toBe(ProductionDeviation::NO_IMPACT)
        ->and($deviation->run_after_id)->not->toBeNull();
});

it('loads the snapshot in a fixed number of queries, whatever the number of orders', function () {
    $shop = PlanningWorkshop::build($this->company, $this->owner);
    $article = $shop['orders'][1042]->article;
    Queue::fake();
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(PlanSnapshot::class)->load($this->company, $this->now);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(1, 7) as $i) {
        PlanningWorkshop::order($this->company, $article, 2000 + $i, 1, [10, 10, 10, 10, 10], $shop['operations'], '2026-12-15', ProductionOrderStatus::Released);
    }

    $ten = $count();

    foreach (range(8, 97) as $i) {
        PlanningWorkshop::order($this->company, $article, 2000 + $i, 1, [10, 10, 10, 10, 10], $shop['operations'], '2026-12-15', ProductionOrderStatus::Released);
    }

    expect($count())->toBe($ten);
});

it('stores the plan\'s times as the moments they are, whatever the session time zone', function () {
    PlanningWorkshop::build($this->company, $this->owner);
    $run = app(ProductionPlanner::class)->run($this->company, PlanTrigger::Manual, $this->now);

    $first = CurrentCompany::run($this->company, fn () => ProductionPlanSlot::query()->where('run_id', $run->id)->orderBy('starts_at')->first());

    expect($first->starts_at->setTimezone(Clock::ZONE)->format('Y-m-d H:i'))->toBe('2026-11-16 07:00')
        ->and(Clock::minute($first->starts_at))->toBe($first->segments[0][0]);
});

it('raises no deviation for an absence of someone outside the workshop, or outside the horizon', function () {
    $shop = PlanningWorkshop::build($this->company, $this->owner);
    app(ProductionPlanner::class)->run($this->company, PlanTrigger::Manual, $this->now);
    $office = Planning::employee($this->owner, $this->company, 'Kontorist', '910707789', ['last_name' => 'Testsson']);

    CurrentCompany::run($this->company, function () use ($office, $shop) {
        Absence::create(['employee_id' => $office->id, 'type' => 'SICK', 'date_from' => '2026-11-17', 'date_to' => '2026-11-18', 'status' => 'APPROVED']);
        Absence::create(['employee_id' => $shop['people']['tove']->id, 'type' => 'VACATION', 'date_from' => '2026-10-01', 'date_to' => '2026-10-02', 'status' => 'APPROVED']);
        Absence::create(['employee_id' => $shop['people']['tove']->id, 'type' => 'VACATION', 'date_from' => '2028-01-10', 'date_to' => '2028-01-11', 'status' => 'APPROVED']);

        expect(ProductionDeviation::query()->count())->toBe(0);
    });

    $this->actingAs($this->owner)->get(route('manufacturing.planning.deviations', ['stangda' => 1]))
        ->assertOk()->assertDontSee('Kontorist')->assertSee('Inga öppna avvikelser');
});
