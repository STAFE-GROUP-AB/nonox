<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Actions\Planning\DecideDeviation;
use App\Modules\Manufacturing\Actions\Planning\HoldOperation;
use App\Modules\Manufacturing\Actions\Planning\SaveDowntime;
use App\Modules\Manufacturing\Actions\Planning\Scenarios;
use App\Modules\Manufacturing\Domain\DeviationStatus;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Livewire\Planning\DeviationsPage;
use App\Modules\Manufacturing\Livewire\Planning\OrdersPage;
use App\Modules\Manufacturing\Livewire\Planning\SetupPage;
use App\Modules\Manufacturing\Models\Downtime;
use App\Modules\Manufacturing\Models\ProductionDeviation;
use App\Modules\Manufacturing\Models\ProductionDeviationImpact;
use App\Modules\Manufacturing\Models\ProductionPlanForecast;
use App\Modules\Manufacturing\Models\ProductionPlanLock;
use App\Modules\Manufacturing\Models\ProductionPlanRun;
use App\Modules\Manufacturing\Models\ProductionPlanSlot;
use App\Modules\Manufacturing\Models\ProductionSkill;
use App\Modules\Manufacturing\Models\Workstation;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\PlanBoard;
use App\Modules\Manufacturing\Planning\ProductionPlanner;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Manufacturing;
use Tests\Support\PlanningWorkshop;

/*
 * AGENTS.md §13.2 for the planning calendar (ADR-071 §13): company A never
 * sees B's plan, resources, locks or deviations; B's ids answer 404 in the
 * pages and the actions; B's plan never takes A's people or orders.
 */

beforeEach(function () {
    $now = CarbonImmutable::parse('2026-11-16 07:00', Clock::ZONE);
    $this->travelTo($now);

    foreach (['a', 'b'] as $side) {
        ['owner' => $owner, 'company' => $company] = Manufacturing::company();
        $shop = PlanningWorkshop::build($company, $owner);
        app(ProductionPlanner::class)->run($company, PlanTrigger::Manual, $now);
        app(SaveDowntime::class)($owner, $company, $shop['lackbox'], ['starts_at' => '2026-11-18 07:00', 'ends_at' => '2026-11-19 16:00', 'reason' => 'BREAKDOWN']);
        $slot = CurrentCompany::run($company, fn () => ProductionPlanSlot::query()->where('run_id', ProductionPlanRun::query()->max('id'))->orderBy('id')->first());
        app(HoldOperation::class)->lock($owner, $company, $slot);
        $this->{$side} = compact('owner', 'company', 'shop', 'slot');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('scopes every planning model to the current company', function (string $model) {
    expect($model::query()->count())->toBeGreaterThan(0)
        ->and($model::query()->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id]);
})->with([ProductionPlanRun::class, ProductionPlanSlot::class, ProductionPlanForecast::class, ProductionPlanLock::class, ProductionDeviation::class, ProductionDeviationImpact::class, Workstation::class, Downtime::class, ProductionSkill::class]);

it('plans each company with its own people, orders and workstations', function () {
    $slots = ProductionPlanSlot::query()->get();
    $aPeople = collect($this->a['shop']['people'])->pluck('id');

    expect($slots->pluck('employee_id')->filter()->diff($aPeople))->toBeEmpty()
        ->and($slots->pluck('workstation_id')->filter()->unique()->values()->all())->toBe([$this->a['shop']['lackbox']->id])
        ->and(app(PlanBoard::class)->latestRunId($this->a['company']))->toBe((int) ProductionPlanRun::query()->max('id'));
});

it('answers 404 for the other company\'s records', function () {
    $bDeviation = CurrentCompany::run($this->b['company'], fn () => ProductionDeviation::query()->first());
    $bLock = CurrentCompany::run($this->b['company'], fn () => ProductionPlanLock::query()->first());

    Livewire::test(OrdersPage::class)->call('lock', $this->b['slot']->id)->assertNotFound();
    Livewire::test(OrdersPage::class)->call('release', $bLock->id)->assertNotFound();
    Livewire::test(OrdersPage::class)->call('savePriority', $this->b['shop']['orders'][1042]->id)->assertNotFound();
    Livewire::test(DeviationsPage::class)->call('decide', $bDeviation->id, 'HANDLED')->assertNotFound();
    Livewire::test(SetupPage::class)->call('editStation', $this->b['shop']['lackbox']->id)->assertNotFound();
    Livewire::test(SetupPage::class)->call('editPerson', $this->b['shop']['people']['tove']->id)->assertNotFound();

    expect(fn () => app(HoldOperation::class)->lock($this->a['owner'], $this->a['company'], $this->b['slot']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(DecideDeviation::class)($this->a['owner'], $this->a['company'], $bDeviation, DeviationStatus::Handled))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(Scenarios::class)->validate($this->a['company'], [['type' => 'PRIORITY', 'production_order_id' => $this->b['shop']['orders'][1042]->id, 'priority' => 1]]))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(Scenarios::class)->validate($this->a['company'], [['type' => 'OVERTIME', 'employee_id' => $this->b['shop']['people']['tove']->id, 'starts_at' => '2026-11-24 16:00', 'ends_at' => '2026-11-24 19:00']]))->toThrow(ValidationException::class);
});
