<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\SalesDocument;
use App\Modules\Manufacturing\Actions\Planning\ConfirmCustomerDate;
use App\Modules\Manufacturing\Actions\Planning\DecideDeviation;
use App\Modules\Manufacturing\Actions\Planning\HoldOperation;
use App\Modules\Manufacturing\Actions\Planning\PlanChanges;
use App\Modules\Manufacturing\Actions\Planning\SaveDowntime;
use App\Modules\Manufacturing\Actions\Planning\Scenarios;
use App\Modules\Manufacturing\Domain\DeviationStatus;
use App\Modules\Manufacturing\Domain\DeviationType;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Mail\DeliveryDateMail;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\Overtime;
use App\Modules\Manufacturing\Models\ProductionDeviation;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Models\ProductionPlanForecast;
use App\Modules\Manufacturing\Models\ProductionPlanRun;
use App\Modules\Manufacturing\Models\ProductionPlanSlot;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\ProductionPlanner;
use App\Modules\Manufacturing\Planning\Suggestions;
use App\Modules\Payroll\Models\Absence;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Manufacturing;
use Tests\Support\PlanningWorkshop;

/*
 * ADR-071 §13.5–13.7: the planner's actions on the database, each audited
 * with ids, enums, dates and minutes only; deviations from downtime, a
 * longer estimate and a new quantity with the impact of §13.9; scenarios
 * computed in memory and applied; suggestions; locks that break; the
 * customer's date confirmed and sent only after review.
 */

beforeEach(function () {
    ['owner' => $this->owner, 'company' => $this->company] = Manufacturing::company();
    $this->now = CarbonImmutable::parse('2026-11-16 07:00', Clock::ZONE);
    $this->travelTo($this->now);
    $this->shop = PlanningWorkshop::build($this->company, $this->owner);
    $this->planner = app(ProductionPlanner::class);
    $this->planner->run($this->company, PlanTrigger::Manual, $this->now);
    CurrentCompany::set($this->company);
});

function latestDelivery(int $orderId): ?string
{
    $run = ProductionPlanRun::query()->latest('id')->first();

    return ProductionPlanForecast::query()->where('run_id', $run->id)->where('production_order_id', $orderId)->first()?->forecast_delivery_on?->toDateString();
}

function operationOf(int $orderId, string $name): ProductionOrderOperation
{
    return ProductionOrderOperation::query()->where('production_order_id', $orderId)->whereHas('operation', fn ($q) => $q->where('name', $name))->sole();
}

it('raises WORKSTATION_DOWN when the Lackbox stops, with the impact of F-P3', function () {
    app(SaveDowntime::class)($this->owner, $this->company, $this->shop['lackbox'], ['starts_at' => '2026-11-18 07:00', 'ends_at' => '2026-11-19 16:00', 'reason' => 'BREAKDOWN']);

    $deviation = ProductionDeviation::query()->where('type', DeviationType::WorkstationDown->value)->sole();
    $impact = $deviation->impacts()->where('production_order_id', $this->shop['orders'][1042]->id)->sole();

    expect($impact->forecast_before_on->toDateString())->toBe('2026-11-20')
        ->and($impact->forecast_after_on->toDateString())->toBe('2026-11-23')
        ->and($impact->change_working_days)->toBe(1)
        ->and($deviation->details)->toEqual(['workstation_id' => $this->shop['lackbox']->id, 'reason' => 'BREAKDOWN']);
});

it('raises OVERRUN for a longer estimate, as F-P4, and QUANTITY_CHANGE for a new quantity', function () {
    $assembly = operationOf($this->shop['orders'][1042]->id, 'Montering');
    app(PlanChanges::class)->remaining($this->owner, $this->company, $assembly, 960);

    expect(latestDelivery($this->shop['orders'][1042]->id))->toBe('2026-11-23')
        ->and(ProductionDeviation::query()->where('type', DeviationType::Overrun->value)->sole()->impacts()->count())->toBe(1);

    $this->shop['orders'][1041]->forceFill(['quantity_milli' => 14_000])->save();

    expect(ProductionDeviation::query()->where('type', DeviationType::QuantityChange->value)->sole()->details)
        ->toEqual(['production_order_id' => $this->shop['orders'][1041]->id, 'before_milli' => 10_000, 'after_milli' => 14_000]);
});

it('keeps locked work, and raises LOCK_BROKEN when the person becomes absent', function () {
    $run = ProductionPlanRun::query()->latest('id')->first();
    $slot = ProductionPlanSlot::query()->where('run_id', $run->id)->where('production_order_operation_id', operationOf($this->shop['orders'][1042]->id, 'Lackering')->id)->sole();
    $lock = app(HoldOperation::class)->lock($this->owner, $this->company, $slot);

    $after = ProductionPlanSlot::query()->where('run_id', ProductionPlanRun::query()->max('id'))->where('production_order_operation_id', $slot->production_order_operation_id)->sole();
    expect($after->locked)->toBeTrue()->and($after->starts_at->equalTo($slot->starts_at))->toBeTrue();

    Absence::create(['employee_id' => $this->shop['people']['tove']->id, 'type' => 'VACATION', 'date_from' => '2026-11-19', 'date_to' => '2026-11-19', 'status' => 'APPROVED']);

    $broken = ProductionDeviation::query()->where('type', DeviationType::LockBroken->value)->sole();
    expect($broken->source_id)->toBe($lock->id)
        ->and(AuditLog::query()->where('action', 'manufacturing.plan_locked')->sole()->payload)->toHaveKeys(['production_order_operation_id', 'employee_id', 'starts_at']);
});

it('moves and splits only to qualified people', function () {
    $assembly = operationOf($this->shop['orders'][1041]->id, 'Montering');
    $hold = app(HoldOperation::class);

    expect(fn () => $hold->assign($this->owner, $this->company, $assembly, $this->shop['people']['tove']->id))->toThrow(ValidationException::class);

    $hold->assign($this->owner, $this->company, $assembly, $this->shop['people']['maja']->id);
    $hold->split($this->owner, $this->company, $assembly, $this->shop['people']['omar']->id, 300);

    $slots = ProductionPlanSlot::query()->where('run_id', ProductionPlanRun::query()->max('id'))->where('production_order_operation_id', $assembly->id)->get();
    expect($slots->pluck('employee_id')->sort()->values()->all())->toBe(collect([$this->shop['people']['maja']->id, $this->shop['people']['omar']->id])->sort()->values()->all())
        ->and(AuditLog::query()->whereIn('action', ['manufacturing.operation_moved', 'manufacturing.operation_split'])->count())->toBe(2);
});

it('computes a scenario without touching the plan, as F-P1a, and applies it', function () {
    Absence::create(['employee_id' => $this->shop['people']['tove']->id, 'type' => 'SICK', 'date_from' => '2026-11-17', 'date_to' => '2026-11-23', 'status' => 'APPROVED']);
    $runs = ProductionPlanRun::query()->count();
    $scenarios = app(Scenarios::class);
    $changes = [['type' => 'OVERTIME', 'employee_id' => $this->shop['people']['tove']->id, 'starts_at' => '2026-11-24 16:00', 'ends_at' => '2026-11-24 19:00']];

    $outcome = $scenarios->outcome($this->owner, $this->company, $changes, $this->now);

    expect($outcome->orders[$this->shop['orders'][1041]->id]['before'])->toBe('2026-11-26')
        ->and($outcome->orders[$this->shop['orders'][1041]->id]['after'])->toBe('2026-11-25')
        ->and($outcome->change($this->shop['orders'][1041]->id))->toBe(-1)
        ->and(ProductionPlanRun::query()->count())->toBe($runs)
        ->and(Overtime::query()->count())->toBe(0);

    $scenarios->apply($this->owner, $this->company, $scenarios->save($this->owner, $this->company, $changes));

    expect(Overtime::query()->count())->toBe(1)
        ->and(latestDelivery($this->shop['orders'][1041]->id))->toBe('2026-11-25')
        ->and(AuditLog::query()->where('action', 'manufacturing.scenario_applied')->sole()->payload)->toEqual(['types' => ['OVERTIME'], 'count' => 1]);
});

it('suggests, by simulation, what helps the most delayed order', function () {
    Absence::create(['employee_id' => $this->shop['people']['tove']->id, 'type' => 'SICK', 'date_from' => '2026-11-17', 'date_to' => '2026-11-23', 'status' => 'APPROVED']);
    $deviation = ProductionDeviation::query()->where('type', DeviationType::Absence->value)->sole();

    $suggestions = collect(app(Suggestions::class)->for($this->company, $deviation, $this->now))->keyBy('kind');

    expect($suggestions->keys()->all())->toContain('OVERTIME', 'PRIORITY')
        ->and($suggestions['OVERTIME']->costOre)->toBe(144_000)
        ->and($suggestions['OVERTIME']->facts)->not->toHaveKey('reason');
});

it('closes deviations, needs production:plan, and keeps names out of the audit log', function () {
    app(SaveDowntime::class)($this->owner, $this->company, $this->shop['lackbox'], ['starts_at' => '2026-11-18 07:00', 'ends_at' => '2026-11-19 16:00', 'reason' => 'MAINTENANCE']);
    $deviation = ProductionDeviation::query()->sole();
    $auditor = Bookkeeping::member($this->company, 'auditor');

    expect(fn () => app(DecideDeviation::class)($auditor, $this->company, $deviation, DeviationStatus::Handled))->toThrow(AuthorizationException::class);

    app(DecideDeviation::class)($this->owner, $this->company, $deviation, DeviationStatus::Dismissed);
    expect($deviation->fresh()->status)->toBe(DeviationStatus::Dismissed);

    $text = AuditLog::query()->where('action', 'like', 'manufacturing.%')->get()->map(fn ($log) => json_encode($log->payload))->join(' ');
    expect($text)->not->toContain('Tove')->not->toContain('Lackbox')->not->toContain('Testsson');
});

it('confirms a delivery date on the order and sends the message only after review', function () {
    Mail::fake();
    $order = Manufacturing::document($this->company, $this->owner, [['P-1041', '10', '1000']], SalesDocument::ORDER);
    $order->customer->forceFill(['email' => 'inkop@kunden.example'])->save();
    $confirm = app(ConfirmCustomerDate::class);
    $accountant = Bookkeeping::member($this->company, 'accountant');

    expect(fn () => $confirm($accountant, $this->company, $order, '2026-11-26'))->toThrow(AuthorizationException::class);

    $change = $confirm($this->owner, $this->company, $order, '2026-11-26');
    expect($order->fresh()->confirmed_delivery_on->toDateString())->toBe('2026-11-26');
    Mail::assertNothingSent();

    $html = (new DeliveryDateMail($this->company, $change))->render();
    expect($html)->toContain('26 november 2026')->not->toContain('Frånvar')->not->toContain('sjuk');

    $confirm->send($this->owner, $this->company, $change, 'inkop@kunden.example');
    Mail::assertSent(DeliveryDateMail::class, fn (DeliveryDateMail $mail) => $mail->hasTo('inkop@kunden.example'));

    expect(fn () => $confirm->send($this->owner, $this->company, $change->fresh(), 'inkop@kunden.example'))->toThrow(ValidationException::class)
        ->and(AuditLog::query()->where('action', 'manufacturing.customer_date_message_sent')->count())->toBe(1);
});

it('gives Planering\'s scheduler view and plan, and the accountant view only', function () {
    $gate = fn ($user) => Gate::forUser($user);
    $accountant = Bookkeeping::member($this->company, 'accountant');

    expect($gate($accountant)->allows('viewAny', [Operation::class, $this->company]))->toBeTrue()
        ->and($gate($accountant)->allows('plan', [Operation::class, $this->company]))->toBeFalse()
        ->and($gate($this->owner)->allows('confirmDates', [Operation::class, $this->company]))->toBeTrue()
        ->and((new ManufacturingModule)->rolePermissions()['scheduler'])->toBe(['manufacturing:view', 'production:plan']);
});
