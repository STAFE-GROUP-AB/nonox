<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Actions\Production\ProductionOrderLifecycle;
use App\Modules\Manufacturing\Actions\Workshop\RecordTerminalTime;
use App\Modules\Manufacturing\Actions\Workshop\WaitingFlags;
use App\Modules\Manufacturing\Domain\LabourSource;
use App\Modules\Manufacturing\Domain\WaitingKind;
use App\Modules\Manufacturing\Livewire\Workshop\AnalysisPage;
use App\Modules\Manufacturing\ManufacturingSettings;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionLabourEntry;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopTimeEntry;
use App\Modules\Manufacturing\Support\WorkshopAnalysis;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Manufacturing;
use Tests\Support\Workshop;

/*
 * The post-calculation (ADR-071 §7): calculated against reported minutes per
 * product and operation, the spread and the trend, the queues against
 * capacity, orders waiting for material kept apart from orders waiting for
 * work, a fixed number of queries, and nothing per person.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Manufacturing::company();
    $this->actingAs($this->owner);
    $this->fx = Manufacturing::fixtures($this->company, $this->owner);
    $this->fx['articles']['table']->forceFill(['is_stock_item' => true])->save();
    Workshop::enable($this->company, $this->owner);
    $this->ops = collect($this->fx['operations'])->map->id;
});

/**
 * A finished order of one table with the minutes reported per operation
 * (null: nothing reported, the plan was used) and the day it finished.
 *
 * @param  array<string, ?int>  $minutes  cut, assembly, lacquering
 */
function finishedOrder(object $test, string $finishedOn, array $minutes, string $quantity = '1'): ProductionOrder
{
    $order = Workshop::order($test, $quantity);

    foreach ($order->operations()->orderBy('position')->get() as $index => $operation) {
        $key = ['cut', 'assembly', 'lacquering'][$index];
        $reported = $minutes[$key] ?? null;

        ProductionLabourEntry::create([
            'production_order_id' => $order->id,
            'production_order_operation_id' => $operation->id,
            'source' => $reported === null ? LabourSource::Planned : LabourSource::Entered,
            'minutes' => $reported ?? $operation->planned_minutes,
            'hourly_cost_ore' => 45_000,
            'cost_ore' => 0,
            'worked_on' => $finishedOn,
        ]);
    }

    $order->forceFill(['status' => 'FINISHED', 'finished_on' => $finishedOn])->save();

    return $order;
}

function plant(object $test): void
{
    // Kapning is calculated at 30 min, Montering 90, Lackering 30.
    foreach ([['2026-08-01', 30, 90], ['2026-09-01', 36, 90], ['2026-10-01', 45, 81], ['2026-10-20', 54, 90]] as [$day, $cut, $assembly]) {
        finishedOrder($test, $day, ['cut' => $cut, 'assembly' => $assembly, 'lacquering' => null]);
    }
}

it('compares calculated and reported minutes per product and operation, with spread and trend', function () {
    plant($this);

    $analysis = WorkshopAnalysis::for(12);
    $rows = collect($analysis['products'])->keyBy('operation');

    expect($rows->keys()->all())->toBe(['Kapning', 'Montering'])
        ->and($rows['Kapning'])->toMatchArray(['article' => 'Ekbord 180', 'orders' => 4, 'planned_minutes' => 120, 'actual_minutes' => 165, 'deviation_percent' => 38, 'spread' => ['min' => 0, 'median' => 35, 'max' => 80], 'series' => [0, 20, 50, 80], 'unreported' => 0])
        ->and($rows['Kapning']['trend'])->toBe(['direction' => 'worse', 'change' => 55])
        ->and($rows['Montering'])->toMatchArray(['planned_minutes' => 360, 'actual_minutes' => 351, 'deviation_percent' => -3])
        ->and($rows['Montering']['trend'])->toBe(['direction' => 'better', 'change' => -5]);
});

it('does not count minutes filled in from the plan as actual time, and says how many orders had none', function () {
    plant($this);
    finishedOrder($this, '2026-10-25', ['cut' => null, 'assembly' => 100]);

    $rows = collect(WorkshopAnalysis::for(12)['products'])->keyBy('operation');

    expect($rows['Kapning'])->toMatchArray(['orders' => 4, 'unreported' => 1])
        ->and($rows['Montering']['orders'])->toBe(5)
        ->and(collect($rows)->has('Lackering'))->toBeFalse();
});

it('says nothing about a trend before four orders', function () {
    finishedOrder($this, '2026-10-01', ['cut' => 40]);
    finishedOrder($this, '2026-10-02', ['cut' => 50]);

    expect(WorkshopAnalysis::for(12)['products'][0]['trend'])->toBeNull();
});

it('keeps to the period', function () {
    finishedOrder($this, '2026-10-01', ['cut' => 40]);
    finishedOrder($this, '2025-01-01', ['cut' => 90]);

    expect(WorkshopAnalysis::for(12)['products'][0]['orders'])->toBe(1)
        ->and(WorkshopAnalysis::for(24)['products'][0]['orders'])->toBe(2)
        ->and(WorkshopAnalysis::for(7)['months'])->toBe(12);
});

it('lists the largest overruns on finished orders', function () {
    plant($this);

    $overruns = WorkshopAnalysis::for(12)['overruns'];

    // Kapning: 0 + 6 + 15 + 24 minutes over, in three of four orders; Montering is under its plan.
    expect($overruns)->toHaveCount(1)
        ->and($overruns[0])->toMatchArray(['operation' => 'Kapning', 'over' => 45, 'over_orders' => 3, 'orders' => 4, 'deviation_percent' => 38]);
});

it('queues the minutes still to do on open orders against each operation\'s capacity', function () {
    Operation::query()->whereKey($this->ops['cut'])->update(['daily_capacity_minutes' => 120]);
    $order = Workshop::order($this, '2');
    [$device] = Workshop::device($this->owner, $this->company);
    $person = Workshop::person($this->owner, $this->company);
    app(RecordTerminalTime::class)($device, $person, $order->operations()->orderBy('position')->first()->id, 0, 20);
    Workshop::order($this, '1', false);

    $queue = collect(WorkshopAnalysis::for(12)['queue'])->keyBy('operation');

    // Kapning: 60 planned - 20 reported = 40 min, capacity 120 min a day. The others have no capacity.
    expect($queue->keys()->all())->toBe(['Kapning', 'Montering', 'Lackering'])
        ->and($queue['Kapning'])->toMatchArray(['remaining_minutes' => 40, 'orders' => 1, 'capacity_minutes' => 120, 'days' => 0.3])
        ->and($queue['Montering'])->toMatchArray(['remaining_minutes' => 180, 'days' => null])
        ->and($queue['Lackering']['remaining_minutes'])->toBe(60);
});

it('keeps orders waiting for material apart from orders waiting for work, with the time they waited', function () {
    [$device] = Workshop::device($this->owner, $this->company);
    $first = Workshop::order($this, '1');
    $second = Workshop::order($this, '1');
    $flags = app(WaitingFlags::class);
    $firstCut = $first->operations()->orderBy('position')->first();
    $secondCut = $second->operations()->orderBy('position')->first();

    $flags->raise($device, $firstCut->id, WaitingKind::Material, 'Ekskivorna är inte levererade');
    $flags->raise($device, $secondCut->id, WaitingKind::Work, 'Sågen är upptagen');
    $this->travel(90)->minutes();
    $flags->clear($device, $secondCut->id);
    $flags->raise($device, $secondCut->id, WaitingKind::Material, 'Lacken är slut');

    $analysis = WorkshopAnalysis::for(12);

    expect(collect($analysis['waiting']['material'])->pluck('reference', 'reason')->all())->toBe(['Ekskivorna är inte levererade' => $first->reference(), 'Lacken är slut' => $second->reference()])
        ->and($analysis['waiting']['work'])->toBe([])
        ->and($analysis['waiting']['material'][0])->toMatchArray(['operation' => 'Kapning', 'product' => 'Ekbord 180', 'minutes' => 90])
        // Work waited 90 minutes (cleared); the open material flags are still counting.
        ->and($analysis['waited']['work'])->toBe(['flags' => 1, 'minutes' => 90])
        ->and($analysis['waited']['material'])->toBe(['flags' => 2, 'minutes' => 90]);
});

it('stops counting a flag when its order is cancelled or finished', function () {
    [$device] = Workshop::device($this->owner, $this->company);
    $order = Workshop::order($this, '1');
    app(WaitingFlags::class)->raise($device, $order->operations()->first()->id, WaitingKind::Work, 'Ingen ledig');
    $this->travel(2)->days();
    app(ProductionOrderLifecycle::class)->cancel($this->owner, $this->company, $order, 'Kunden avbeställde');

    $analysis = WorkshopAnalysis::for(12);

    expect($analysis['waiting']['work'])->toBe([])
        ->and($analysis['waited']['work']['flags'])->toBe(1)
        // From 10:00 on the 10th to the end of the day the order was cancelled (the 12th).
        ->and($analysis['waited']['work']['minutes'])->toBe(2 * 24 * 60 + 14 * 60 - 1);
});

it('runs a fixed number of queries whatever the number of products and orders', function () {
    $count = function (): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        WorkshopAnalysis::for(12);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    plant($this);
    [$device] = Workshop::device($this->owner, $this->company);
    app(WaitingFlags::class)->raise($device, Workshop::order($this, '1')->operations()->first()->id, WaitingKind::Material, 'Skivor');
    $few = $count();

    foreach (range(1, 12) as $day) {
        finishedOrder($this, CarbonImmutable::parse('2026-09-01')->addDays($day)->toDateString(), ['cut' => 20 + $day, 'assembly' => 80 + $day]);
        app(WaitingFlags::class)->raise($device, Workshop::order($this, '1')->operations()->first()->id, WaitingKind::Work, "Orsak {$day}");
    }

    expect($count())->toBe($few)->and($few)->toBeLessThanOrEqual(20);
});

it('reads no person, only how many different people reported on a product and operation', function () {
    plant($this);
    [$device] = Workshop::device($this->owner, $this->company);
    app(RecordTerminalTime::class)($device, Workshop::person($this->owner, $this->company), Workshop::order($this, '1')->operations()->first()->id, 1, 0);

    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = strtolower($query->sql);
    });
    WorkshopAnalysis::for(12);

    $touching = array_values(array_filter($sql, fn (string $query): bool => str_contains($query, 'workshop_time_entries')));

    expect($sql)->not->toBeEmpty()
        ->and(implode("\n", $sql))->not->toContain('workshop_people')
        ->and($touching)->toHaveCount(2)
        ->and(collect($touching)->every(fn (string $query): bool => str_contains($query, 'count(*) as people') && str_contains($query, 'having sum(entries.minutes) > 0')))->toBeTrue();
});

it('hides spread, series and trend where fewer than three people reported, and shows them from three', function () {
    plant($this);
    [$device] = Workshop::device($this->owner, $this->company);
    $people = collect(['Anna Lind', 'Bo Berg', 'Cia Ek'])->map(fn (string $name) => Workshop::person($this->owner, $this->company, $name));
    $cut = fn (): int => Operation::query()->where('name', 'Kapning')->value('id');

    // The planted orders' Kapning was entered at finish: no person, so nothing is hidden.
    $find = fn () => collect(WorkshopAnalysis::for(12)['products'])->firstWhere('operation', 'Kapning');
    expect($find()['detail_hidden'])->toBeFalse()->and($find()['spread'])->not->toBeNull();

    // Two people report on a finished order's Kapning (written as terminal time on an order that is then finished).
    $order = finishedOrder($this, '2026-10-28', ['cut' => 40]);
    $step = $order->operations()->orderBy('position')->first();
    foreach ([$people[0], $people[1]] as $person) {
        $labour = ProductionLabourEntry::create(['production_order_id' => $order->id, 'production_order_operation_id' => $step->id, 'source' => 'TERMINAL', 'minutes' => 1, 'hourly_cost_ore' => 45_000, 'cost_ore' => 0, 'worked_on' => '2026-10-28']);
        WorkshopTimeEntry::create(['person_id' => $person->id, 'device_id' => $device->id, 'production_order_id' => $order->id, 'production_order_operation_id' => $step->id, 'production_labour_entry_id' => $labour->id, 'minutes' => 1, 'worked_on' => '2026-10-28', 'entered_at' => '2026-10-28 10:00:00']);
    }

    $hidden = $find();
    expect($hidden['detail_hidden'])->toBeTrue()->and($hidden['spread'])->toBeNull()->and($hidden['series'])->toBe([])->and($hidden['trend'])->toBeNull()
        ->and($hidden['planned_minutes'])->toBeGreaterThan(0)->and($hidden['orders'])->toBe(5);

    $labour = ProductionLabourEntry::create(['production_order_id' => $order->id, 'production_order_operation_id' => $step->id, 'source' => 'TERMINAL', 'minutes' => 1, 'hourly_cost_ore' => 45_000, 'cost_ore' => 0, 'worked_on' => '2026-10-28']);
    WorkshopTimeEntry::create(['person_id' => $people[2]->id, 'device_id' => $device->id, 'production_order_id' => $order->id, 'production_order_operation_id' => $step->id, 'production_labour_entry_id' => $labour->id, 'minutes' => 1, 'worked_on' => '2026-10-28', 'entered_at' => '2026-10-28 10:00:00']);

    expect($find()['detail_hidden'])->toBeFalse()->and($find()['spread'])->not->toBeNull()->and($cut())->toBeInt();
    $this->get(route('manufacturing.analysis'))->assertOk();
});

it('shows the page to production:analyse only and carries no per-person view', function () {
    plant($this);
    $this->get(route('manufacturing.analysis'))->assertOk()->assertSee('Efterkalkyl')->assertSee('Ekbord 180')->assertSee('Kapning')->assertSee('+38 %')->assertSee('Försämras');
    Livewire::test(AnalysisPage::class)->assertOk()->assertDontSee('per person', false);

    foreach (['accountant' => 200, 'auditor' => 403, 'bookkeeper' => 403, 'approver' => 403] as $role => $status) {
        $user = Bookkeeping::member($this->company, $role);
        CurrentCompany::set($this->company);
        $this->actingAs($user)->get(route('manufacturing.analysis'))->assertStatus($status);
    }
});

it('has no per-person setting at all', function () {
    expect(ManufacturingSettings::DEFAULTS)->not->toHaveKey('per_person_analysis')
        ->and(Schema::hasColumn('workshop_time_entries', 'per_person_analysis'))->toBeFalse();

    $this->actingAs($this->owner)->get(route('manufacturing.settings'))->assertOk()->assertDontSee('per person', false)->assertDontSee('Per person');
});

/** @param  list<WorkshopPerson>  $people */
function reportedBy(object $test, WorkshopDevice $device, array $people, ProductionOrder $order, int $minutes = 1): void
{
    $step = $order->operations()->orderBy('position')->first();

    foreach ($people as $person) {
        $labour = ProductionLabourEntry::create(['production_order_id' => $order->id, 'production_order_operation_id' => $step->id, 'source' => 'TERMINAL', 'minutes' => $minutes, 'hourly_cost_ore' => 45_000, 'cost_ore' => 0, 'worked_on' => '2026-10-28']);
        WorkshopTimeEntry::create(['person_id' => $person->id, 'device_id' => $device->id, 'production_order_id' => $order->id, 'production_order_operation_id' => $step->id, 'production_labour_entry_id' => $labour->id, 'minutes' => $minutes, 'worked_on' => '2026-10-28', 'entered_at' => '2026-10-28 10:00:00']);
    }
}

it('hides the overruns of an operation that fewer than three people reported on, and says how many', function () {
    plant($this);
    expect(WorkshopAnalysis::for(12))->toMatchArray(['overruns_hidden' => 0])->and(WorkshopAnalysis::for(12)['overruns'])->toHaveCount(1);

    [$device] = Workshop::device($this->owner, $this->company);
    $order = finishedOrder($this, '2026-10-28', ['cut' => 40]);
    $people = collect(['Anna Lind', 'Bo Berg', 'Cia Ek'])->map(fn (string $name) => Workshop::person($this->owner, $this->company, $name));
    reportedBy($this, $device, [$people[0], $people[1]], $order);

    $hidden = WorkshopAnalysis::for(12);
    expect($hidden['overruns'])->toBe([])->and($hidden['overruns_hidden'])->toBe(1);

    reportedBy($this, $device, [$people[2]], $order);
    expect(WorkshopAnalysis::for(12)['overruns'])->toHaveCount(1)->and(WorkshopAnalysis::for(12)['overruns_hidden'])->toBe(0);
});

it('counts only people whose minutes add up to more than nothing', function () {
    plant($this);
    [$device] = Workshop::device($this->owner, $this->company);
    $order = finishedOrder($this, '2026-10-28', ['cut' => 40]);
    $people = collect(['Anna Lind', 'Bo Berg', 'Cia Ek'])->map(fn (string $name) => Workshop::person($this->owner, $this->company, $name));
    reportedBy($this, $device, [$people[0], $people[1]], $order);

    // Cia reports and is corrected away: she must not make three.
    reportedBy($this, $device, [$people[2]], $order, 30);
    reportedBy($this, $device, [$people[2]], $order, -30);

    $row = collect(WorkshopAnalysis::for(12)['products'])->firstWhere('operation', 'Kapning');
    expect($row['detail_hidden'])->toBeTrue();
});
