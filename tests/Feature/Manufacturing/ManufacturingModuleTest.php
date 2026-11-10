<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Manufacturing\Actions\SaveOperation;
use App\Modules\Manufacturing\Livewire\ManufacturingSettingsForm;
use App\Modules\Manufacturing\Livewire\OperationsPage;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\ManufacturingSettings;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Manufacturing;

/*
 * Tillverkning as a module (ADR-071 §1, ADR-017): off by default, needs
 * Lager, its pages answer 404 while it is off, the permissions per role,
 * operations with their hourly cost, and the overhead setting.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company] = Manufacturing::company();
    $this->actingAs($this->owner);
});

it('is installed, needs Lager, and cannot be turned on without it', function () {
    expect(app(ModuleRegistry::class)->get(ManufacturingModule::KEY)->name())->toBe('Tillverkning')
        ->and(app(ModuleRegistry::class)->get(ManufacturingModule::KEY)->dependencies())->toBe([InventoryModule::KEY]);

    [$owner, $company] = Bookkeeping::company();
    expect(app(ModuleManager::class)->isEnabled(ManufacturingModule::KEY, $company))->toBeFalse();
    expect(fn () => app(ModuleManager::class)->enable(ManufacturingModule::KEY, $company, $owner))->toThrow(ValidationException::class);

    Inventory::enable($company, $owner);
    app(ModuleManager::class)->enable(ManufacturingModule::KEY, $company, $owner);
    expect(app(ModuleManager::class)->isEnabled(ManufacturingModule::KEY, $company))->toBeTrue();
});

it('answers 404 on every page while the module is off', function () {
    $fx = Manufacturing::fixtures($this->company, $this->owner);
    $routes = ['manufacturing.boms.index', 'manufacturing.boms.create', 'manufacturing.operations', 'manufacturing.settings'];

    foreach ($routes as $route) {
        $this->get(route($route))->assertOk();
    }

    $this->get(route('manufacturing.boms.show', $fx['articles']['table']->id))->assertOk()->assertSee('Ekbord 180');
    $this->get('/tillverkning')->assertRedirect('/tillverkning/stycklistor');

    app(ModuleManager::class)->disable(ManufacturingModule::KEY, $this->company, $this->owner, confirmed: true);

    foreach ($routes as $route) {
        $this->get(route($route))->assertNotFound();
    }

    $this->get(route('manufacturing.boms.show', $fx['articles']['table']->id))->assertNotFound();
});

it('gives each role its permissions', function (string $role, array $abilities) {
    $user = Bookkeeping::member($this->company, $role);
    $this->company->unsetRelation('users');
    $gate = Gate::forUser($user);

    foreach (['viewAny', 'manage'] as $ability) {
        expect($gate->allows($ability, [Operation::class, $this->company]))->toBe(in_array($ability, $abilities, true), "{$role} {$ability}");
    }
})->with([
    'admin' => ['admin', ['viewAny', 'manage']],
    'accountant' => ['accountant', ['viewAny']],
    'bookkeeper' => ['bookkeeper', []],
    'approver' => ['approver', []],
    'auditor' => ['auditor', ['viewAny']],
]);

it('shows the pages read-only to view, and refuses changes', function () {
    $fx = Manufacturing::fixtures($this->company, $this->owner);
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $this->actingAs($auditor);

    $this->get(route('manufacturing.boms.index'))->assertOk()->assertDontSee('Ny stycklista');
    $this->get(route('manufacturing.operations'))->assertOk()->assertSee('Kapning')->assertDontSee('Nytt arbetssteg');
    $this->get(route('manufacturing.boms.create'))->assertForbidden();

    expect(fn () => app(SaveOperation::class)($auditor, $this->company, ['name' => 'Slipning', 'hourly_cost' => '400']))->toThrow(AuthorizationException::class);

    $this->actingAs(Bookkeeping::member($this->company, 'bookkeeper'));
    $this->get(route('manufacturing.boms.index'))->assertForbidden();
    expect($fx['bom']->id)->toBeInt();
});

it('adds, changes and archives operations, audited without names', function () {
    Livewire::test(OperationsPage::class)
        ->call('create')
        ->set('form.name', 'Slipning')
        ->set('form.hourly_cost', '420,50')
        ->set('form.capacity_hours', '7,5')
        ->call('save')
        ->assertDispatched('toast', message: 'Arbetssteget Slipning är tillagt.');

    $operation = Operation::query()->sole();
    expect($operation->hourly_cost_ore)->toBe(42_050)
        ->and($operation->daily_capacity_minutes)->toBe(450);

    Livewire::test(OperationsPage::class)
        ->call('edit', $operation->id)
        ->assertSet('form.hourly_cost', '420,50')
        ->assertSet('form.capacity_hours', '7,5')
        ->set('form.hourly_cost', '450')
        ->set('form.capacity_hours', '')
        ->call('save')
        ->call('archive', $operation->id, true)
        ->assertDispatched('toast', message: 'Arbetssteget Slipning är arkiverat.')
        ->assertSee('Inga arbetssteg ännu')
        ->set('showArchived', true)
        ->assertSee('Arkiverat');

    expect($operation->fresh())->hourly_cost_ore->toBe(45_000)->daily_capacity_minutes->toBeNull()->archived_at->not->toBeNull();

    $audit = AuditLog::query()->where('action', 'manufacturing.operation_updated')->sole();
    expect($audit->payload)->toMatchArray(['hourly_cost_ore' => 45_000, 'daily_capacity_minutes' => null, 'before' => ['hourly_cost_ore' => 42_050, 'daily_capacity_minutes' => 450]])
        ->and(json_encode($audit->payload))->not->toContain('Slipning');
});

it('refuses a missing name, a duplicate, a negative cost and an unreadable capacity', function (array $input, string $field) {
    app(SaveOperation::class)($this->owner, $this->company, ['name' => 'Kapning', 'hourly_cost' => '450']);

    try {
        app(SaveOperation::class)($this->owner, $this->company, $input);
        $this->fail('Saved.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'no name' => [['name' => ' ', 'hourly_cost' => '450'], 'name'],
    'a duplicate' => [['name' => 'kapning', 'hourly_cost' => '450'], 'name'],
    'no cost' => [['name' => 'Hyvling', 'hourly_cost' => ''], 'hourly_cost'],
    'a negative cost' => [['name' => 'Hyvling', 'hourly_cost' => '-1'], 'hourly_cost'],
    'an unreadable capacity' => [['name' => 'Hyvling', 'hourly_cost' => '450', 'capacity_hours' => 'mycket'], 'capacity_hours'],
    'a zero capacity' => [['name' => 'Hyvling', 'hourly_cost' => '450', 'capacity_hours' => '0'], 'capacity_hours'],
]);

it('saves the overhead from the settings page', function () {
    Livewire::test(ManufacturingSettingsForm::class, ['company' => $this->company])
        ->set('overhead', '12,5')
        ->call('save')
        ->assertDispatched('toast', message: 'Inställningarna för tillverkning är sparade.');

    expect(ManufacturingSettings::overheadBp($this->company))->toBe(1_250);

    Livewire::test(ManufacturingSettingsForm::class, ['company' => $this->company])
        ->assertSet('overhead', '12,5')
        ->set('overhead', 'tio')
        ->call('save')
        ->assertHasErrors('overhead')
        ->set('overhead', '')
        ->call('save');

    expect(ManufacturingSettings::overheadBp($this->company))->toBe(0);
});

it('lists operations in a fixed number of queries', function () {
    $save = app(SaveOperation::class);
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(OperationsPage::class)->assertSee('Steg 1');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(1, 2) as $index) {
        $save($this->owner, $this->company, ['name' => "Steg {$index}", 'hourly_cost' => '400']);
    }

    $count();
    $few = $count();

    foreach (range(3, 25) as $index) {
        $save($this->owner, $this->company, ['name' => "Steg {$index}", 'hourly_cost' => '400']);
    }

    expect($count())->toBe($few);
});
