<?php

declare(strict_types=1);

/*
 * ADR-074 §1, §2: the module, its permissions per role, the field worker
 * who sees only assigned work orders (O3), and the work order's own rules:
 * number series, a project per work order (O2), reverse charge copied and
 * locked (AO-R20), ROT/RUT only for private persons with a property
 * (AO-R15), several buyers (O6), access notes encrypted and removed at
 * close (AO-R39).
 */

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Project;
use App\Modules\ModuleDisabledException;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Actions\ChangeWorkOrderStatus;
use App\Modules\WorkOrders\Actions\SaveWorkOrder;
use App\Modules\WorkOrders\Domain\WorkOrderStatus;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderBuyer;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Modules\WorkOrders\WorkOrdersSettings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\WorkOrders;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);
});

it('is off by default, answers 404 until it is turned on, and seeds Arbete and Resa', function () {
    $this->get(route('workOrders.index'))->assertNotFound();

    WorkOrders::enable($this->company, $this->owner);

    $this->get(route('workOrders.index'))->assertOk()->assertSee('Inga arbetsorder ännu');
    expect(WorkOrders::kind('ARBETE')->isLabour())->toBeTrue()
        ->and(WorkOrders::kind('RESA')->isLabour())->toBeFalse()
        ->and(WorkOrdersSettings::autoProject($this->company))->toBeTrue();
});

it('allows and denies per role (ADR-074 §1), and grants only the archive view while the module is off', function (string $role, array $allowed) {
    $member = Bookkeeping::member($this->company, $role);

    foreach ([WorkOrdersModule::MANAGE, WorkOrdersModule::REPORT, WorkOrdersModule::SEND] as $permission) {
        expect($member->hasTeamPermission($this->company, $permission))->toBeFalse();
    }

    WorkOrders::enable($this->company, $this->owner);
    $member = $member->fresh();

    foreach (WorkOrdersModule::PERMISSIONS as $permission) {
        expect($member->hasTeamPermission($this->company, $permission))->toBe(in_array($permission, $allowed, true), "{$role} / {$permission}");
    }
})->with([
    'admin' => ['admin', WorkOrdersModule::PERMISSIONS],
    'accountant' => ['accountant', ['work-orders:view', 'work-orders:manage']],
    'bookkeeper' => ['bookkeeper', ['work-orders:view', 'work-orders:manage', 'change-orders:send']],
    'approver' => ['approver', []],
    'auditor' => ['auditor', ['work-orders:view']],
    'field worker' => ['field_worker', ['work-orders:view', 'work-orders:report']],
]);

it('numbers work orders, creates a project per work order unless switched off, and audits ids only', function () {
    WorkOrders::enable($this->company, $this->owner);
    $customer = WorkOrders::privateCustomer($this->company, $this->owner);

    $first = WorkOrders::workOrder($this->company, $this->owner, $customer, ['access_notes' => 'Portkod 1234']);
    $second = WorkOrders::workOrder($this->company, $this->owner, $customer, ['create_project' => false]);

    expect($second->number)->toBe($first->number + 1)
        ->and(Project::query()->find($first->project_id)?->code)->toBe("AO{$first->number}")
        ->and($second->project_id)->toBeNull();

    $log = AuditLog::query()->where('action', 'work_orders.created')->where('subject_id', (string) $first->id)->firstOrFail();
    expect(json_encode($log->payload))->not->toContain('Portkod')->not->toContain('Eva');
});

it('copies reverse charge from the customer and refuses ROT on it (AO-R20, F4)', function () {
    WorkOrders::enable($this->company, $this->owner);
    $builder = WorkOrders::business($this->company, $this->owner, extra: ['reverse_charge_construction' => true]);

    $workOrder = WorkOrders::workOrder($this->company, $this->owner, $builder);
    expect($workOrder->reverse_charge)->toBeTrue();

    expect(fn () => app(SaveWorkOrder::class)($this->owner, $this->company, ['customer_id' => $builder->id, 'title' => 'Vägg', 'price_form' => 'TIME_AND_MATERIAL', 'tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad 1:1'], $workOrder))
        ->toThrow(ValidationException::class, 'omvänd betalningsskyldighet');
    expect(fn () => DB::table('work_orders')->where('id', $workOrder->id)->update(['tax_reduction' => 'ROT']))->toThrow(QueryException::class);
});

it('offers ROT only to a private person, and ROT needs the property or the association and apartment (F5, AO-R15)', function () {
    WorkOrders::enable($this->company, $this->owner);
    $association = WorkOrders::business($this->company, $this->owner, 'Brf Exemplet', '769900456');
    $person = WorkOrders::privateCustomer($this->company, $this->owner);

    expect(fn () => WorkOrders::workOrder($this->company, $this->owner, $association, ['tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad 1:1']))
        ->toThrow(ValidationException::class, 'privatpersoner');
    expect(fn () => WorkOrders::workOrder($this->company, $this->owner, $person, ['tax_reduction' => 'ROT']))
        ->toThrow(ValidationException::class, 'fastighetsbeteckningen');

    $apartment = WorkOrders::workOrder($this->company, $this->owner, $person, ['tax_reduction' => 'ROT', 'housing_association_org_number' => WorkOrders::orgNumber('769900456'), 'apartment_number' => '1102']);
    expect($apartment->tax_reduction)->toBe('ROT');

    $association->refresh();
    expect(WorkOrders::workOrder($this->company, $this->owner, $association)->reverse_charge)->toBeFalse();
});

it('requires the labour part of a fixed price for ROT', function () {
    WorkOrders::enable($this->company, $this->owner);
    $person = WorkOrders::privateCustomer($this->company, $this->owner);

    expect(fn () => WorkOrders::workOrder($this->company, $this->owner, $person, ['price_form' => 'FIXED', 'contract_sum' => '64 000', 'tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad Ekbacken 3:14']))
        ->toThrow(ValidationException::class, 'arbetskostnad');
});

it('keeps several ROT buyers with shares adding up to 100 % (O6)', function () {
    WorkOrders::enable($this->company, $this->owner);
    $eva = WorkOrders::privateCustomer($this->company, $this->owner);
    $olle = WorkOrders::privateCustomer($this->company, $this->owner, 'Olle Exempelsson', '820202234');
    $base = ['tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad Ekbacken 3:14'];

    expect(fn () => WorkOrders::workOrder($this->company, $this->owner, $eva, [...$base, 'buyers' => [['customer_id' => $eva->id, 'share' => '50'], ['customer_id' => $olle->id, 'share' => '40']]]))
        ->toThrow(ValidationException::class, '100 procent');

    $workOrder = WorkOrders::workOrder($this->company, $this->owner, $eva, [...$base, 'buyers' => [['customer_id' => $eva->id, 'share' => '50'], ['customer_id' => $olle->id, 'share' => '50']]]);

    expect(WorkOrderBuyer::query()->where('work_order_id', $workOrder->id)->pluck('share_bp')->all())->toBe([5000, 5000]);
});

it('stores the access notes encrypted, never in the audit log, and removes them when the work order is closed (AO-R39)', function () {
    WorkOrders::enable($this->company, $this->owner);
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::privateCustomer($this->company, $this->owner), ['access_notes' => 'Portkod 4711, nyckel under mattan']);

    expect(DB::table('work_orders')->where('id', $workOrder->id)->value('access_notes'))->not->toContain('4711')
        ->and($workOrder->fresh()->access_notes)->toBe('Portkod 4711, nyckel under mattan');

    $change = app(ChangeWorkOrderStatus::class);
    $change($this->owner, $this->company, $workOrder, WorkOrderStatus::Done);
    $change($this->owner, $this->company, $workOrder->fresh(), WorkOrderStatus::Closed);

    expect($workOrder->fresh()->access_notes)->toBeNull()
        ->and(AuditLog::query()->where('company_id', $this->company->id)->get()->pluck('payload')->toJson())->not->toContain('4711');
});

it('stops its actions when it is turned off and keeps the data', function () {
    WorkOrders::enable($this->company, $this->owner);
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::privateCustomer($this->company, $this->owner));

    app(ModuleManager::class)->disable(WorkOrdersModule::KEY, $this->company, $this->owner, confirmed: true);

    expect(fn () => WorkOrders::hours($this->company, $this->owner, $workOrder, '1'))->toThrow(ModuleDisabledException::class);
    expect(WorkOrder::query()->find($workOrder->id))->not->toBeNull();
    $this->get(route('workOrders.show', $workOrder->id))->assertNotFound();
});

it('offers the field worker role only while the module is on', function () {
    expect(Company::query()->count())->toBeGreaterThan(0);
    $member = Bookkeeping::member($this->company, 'field_worker');

    expect($member->hasTeamPermission($this->company, WorkOrdersModule::REPORT))->toBeFalse();

    WorkOrders::enable($this->company, $this->owner);

    expect($member->fresh()->hasTeamPermission($this->company, WorkOrdersModule::REPORT))->toBeTrue();
});
