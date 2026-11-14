<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\Livewire\Planning\CalendarPage;
use App\Modules\Manufacturing\Livewire\Planning\DeviationsPage;
use App\Modules\Manufacturing\Livewire\Planning\OrdersPage;
use App\Modules\Manufacturing\Livewire\Planning\ResourcesPage;
use App\Modules\Manufacturing\Livewire\Planning\TimelinePage;
use App\Modules\Manufacturing\Models\ProductionDeviation;
use App\Modules\Manufacturing\Models\ProductionPlanForecast;
use App\Modules\Manufacturing\Models\ProductionPlanRun;
use App\Modules\Manufacturing\Models\ProductionPlanSlot;
use App\Modules\Manufacturing\Models\ProductionScenario;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\ProductionPlanner;
use App\Modules\Payroll\Models\Absence;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Manufacturing;
use Tests\Support\PlanningWorkshop;

/*
 * ADR-071 §13.1, §13.4, §13.7: the planning screens render the plan,
 * poll the latest run, show absence only as "Frånvarande", need
 * manufacturing:view, and run a fixed number of queries.
 */

beforeEach(function () {
    ['owner' => $this->owner, 'company' => $this->company] = Manufacturing::company();
    $this->now = CarbonImmutable::parse('2026-11-16 07:00', Clock::ZONE);
    $this->travelTo($this->now);
    $this->shop = PlanningWorkshop::build($this->company, $this->owner);
    app(ProductionPlanner::class)->run($this->company, PlanTrigger::Manual, $this->now);
    $this->actingAs($this->owner);
});

it('renders every planning screen with the plan', function (string $route, array $sees) {
    $this->get(route($route))->assertOk()->assertSee($sees);
})->with([
    'timeline' => ['manufacturing.planning.timeline', ['TO-1042', 'Byrå 80', 'Kapning', 'I tid', 'wire:poll.15s.visible']],
    'orders' => ['manufacturing.planning.orders', ['TO-1041', '2026-11-25', '2026-11-23', 'Väntar på']],
    'resources' => ['manufacturing.planning.resources', ['Tove Testsson', 'Lackbox']],
    'calendar' => ['manufacturing.planning.calendar', ['Leverans TO-1042']],
    'deviations' => ['manufacturing.planning.deviations', ['Inga öppna avvikelser']],
    'scenario' => ['manufacturing.planning.scenario', ['Lägg till en ändring']],
    'setup' => ['manufacturing.planning.setup', ['Lackbox', 'Lackering']],
]);

it('shows absence only as Frånvarande, never its kind', function () {
    CurrentCompany::run($this->company, fn () => Absence::create([
        'employee_id' => $this->shop['people']['tove']->id, 'type' => 'SICK', 'date_from' => '2026-11-17', 'date_to' => '2026-11-18', 'status' => 'APPROVED', 'note' => 'Influensa',
    ]));

    foreach ([route('manufacturing.planning.resources'), route('manufacturing.planning.deviations', ['stangda' => 1])] as $url) {
        $this->get($url)->assertOk()->assertSee('Frånvarande')->assertDontSee(['Sjuk', 'SICK', 'Influensa', 'sjukfrånvaro']);
    }
});

it('renders again only when a new run exists', function () {
    $page = Livewire::test(TimelinePage::class);
    $runId = $page->get('runId');

    $page->call('checkRun')->assertSet('runId', $runId);

    app(ProductionPlanner::class)->run($this->company, PlanTrigger::Manual, $this->now);
    $page->call('checkRun')->assertNotSet('runId', $runId);
});

it('needs manufacturing:view, and production:plan for the planner screens and actions', function () {
    $this->actingAs(Bookkeeping::member($this->company, 'auditor'));
    $this->get(route('manufacturing.planning.timeline'))->assertOk()->assertDontSee('Beräkna nu');
    $this->get(route('manufacturing.planning.scenario'))->assertForbidden();
    Livewire::test(OrdersPage::class)->call('savePriority', $this->shop['orders'][1042]->id)->assertForbidden();

    $this->actingAs(Bookkeeping::member($this->company, 'bookkeeper'));
    $this->get(route('manufacturing.planning.timeline'))->assertForbidden();
});

it('runs a fixed number of queries on each screen, whatever the number of orders', function (string $component) {
    Queue::fake();
    $article = $this->shop['orders'][1042]->article;
    $planner = app(ProductionPlanner::class);
    $count = function () use ($component): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test($component);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(1, 7) as $i) {
        PlanningWorkshop::order($this->company, $article, 3000 + $i, 1, [10, 10, 10, 10, 10], $this->shop['operations'], '2026-11-30', ProductionOrderStatus::Released);
    }

    $planner->run($this->company, PlanTrigger::Manual, $this->now);
    // The first render looks the company up once; measure warm.
    $count();
    $ten = $count();

    foreach (range(8, 97) as $i) {
        PlanningWorkshop::order($this->company, $article, 3000 + $i, 1, [10, 10, 10, 10, 10], $this->shop['operations'], '2026-11-30', ProductionOrderStatus::Released);
    }

    $planner->run($this->company, PlanTrigger::Manual, $this->now);

    expect($count())->toBe($ten);
})->with([TimelinePage::class, OrdersPage::class, ResourcesPage::class, CalendarPage::class, DeviationsPage::class]);

it('computes suggestions once, applies exactly the one shown, and refuses it after the plan changed', function () {
    Absence::query();
    CurrentCompany::run($this->company, fn () => Absence::create([
        'employee_id' => $this->shop['people']['tove']->id, 'type' => 'SICK', 'date_from' => '2026-11-17', 'date_to' => '2026-11-23', 'status' => 'APPROVED',
    ]));
    $deviation = CurrentCompany::run($this->company, fn () => ProductionDeviation::query()->sole());

    $page = Livewire::test(DeviationsPage::class)->call('suggest', $deviation->id);
    $shown = $page->get('shown');
    expect($shown)->not->toBeEmpty();

    // With the panel open, a render runs no simulation: the same queries as with it closed.
    $count = function () use ($page): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $open = $count();
    $closed = Livewire::test(DeviationsPage::class);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $closed->call('$refresh');
    $closedQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($open)->toBe($closedQueries);

    // A hash that is not the shown suggestion's is refused.
    $index = collect($shown)->search(fn (array $s): bool => $s['changes'] !== [] && $s['helps']);
    $page->call('apply', $index, 'not-the-hash')->assertSet('shown', $shown);
    expect(ProductionScenario::query()->count())->toBe(0);

    // After a new run the shown suggestions are stale.
    app(ProductionPlanner::class)->run($this->company, PlanTrigger::Manual, $this->now);
    $page->call('checkRun')->call('apply', $index, $shown[$index]['hash']);
    expect(ProductionScenario::query()->count())->toBe(0);

    // Shown again on the latest plan, it applies.
    $page->call('suggest', $deviation->id)->call('suggest', $deviation->id);
    $fresh = $page->get('shown');
    $page->call('apply', $index, $fresh[$index]['hash']);
    expect(ProductionScenario::query()->whereNotNull('applied_at')->sole()->changes)->toEqual($fresh[$index]['changes']);
});

it('shows when the work is done, and the waiting time after it, not the finish after drying as if work ended at night', function () {
    $run = ProductionPlanRun::query()->latest('id')->first();
    $order = $this->shop['orders'][1042];
    $lastEnd = CurrentCompany::run($this->company, fn () => ProductionPlanSlot::query()->where('run_id', $run->id)->where('production_order_id', $order->id)->max('ends_at'));
    $forecast = CurrentCompany::run($this->company, fn () => ProductionPlanForecast::query()->where('run_id', $run->id)->where('production_order_id', $order->id)->sole());

    // The rule (ADR-071 §13.3 step 5): the work ends inside working hours, the finish adds the 12 hours' wait.
    expect(CarbonImmutable::parse($lastEnd)->setTimezone(Clock::ZONE)->format('Y-m-d H:i'))->toBe('2026-11-19 13:30')
        ->and($forecast->finish_at->setTimezone(Clock::ZONE)->format('Y-m-d H:i'))->toBe('2026-11-20 01:30');

    $this->get(route('manufacturing.planning.calendar'))->assertOk()
        ->assertSee('Arbetet klart TO-1042 13:30')
        ->assertSee('väntetid till 20/11 01:30')
        ->assertDontSee('Klar TO-1042 01:30');
});
