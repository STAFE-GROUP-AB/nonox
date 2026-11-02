<?php

declare(strict_types=1);

use App\Modules\Livewire\CompanyModulesTab;
use App\Modules\ModuleDisabledException;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Livewire\PlanningSettingsForm;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * Planering as a module (PLANNING_MODULE.md §2–3): off by default, needs Lön,
 * 404 while off, its pages in the sidebar while on, the scheduler role, and
 * its settings under Moduler.
 */

const PLANNING_PAGES = ['planning.schedule', 'planning.templates', 'planning.settings'];

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->modules = app(ModuleManager::class);
    $this->actingAs($this->owner);
});

it('is installed, needs payroll, and answers 404 until it is turned on', function () {
    expect(app(ModuleRegistry::class)->get(PlanningModule::KEY)->dependencies())->toBe([PayrollModule::KEY]);

    foreach (PLANNING_PAGES as $route) {
        $this->get(route($route))->assertNotFound();
    }

    expect(fn () => $this->modules->enable(PlanningModule::KEY, $this->company, $this->owner))->toThrow(ValidationException::class);

    Payroll::setUp($this->company, $this->owner);
    $this->modules->enable(PlanningModule::KEY, $this->company, $this->owner);

    foreach (PLANNING_PAGES as $route) {
        $this->get(route($route))->assertOk();
    }

    $this->get(route('dashboard'))->assertSee(route('planning.schedule'), escape: false);
    expect($this->modules->settings(PlanningModule::KEY, $this->company))->toEqual(PlanningSettings::DEFAULTS);

    [$otherOwner] = Bookkeeping::company();
    $this->actingAs($otherOwner)->get(route('planning.schedule'))->assertNotFound();
});

it('keeps payroll on while Planering is on, and stops its actions when it is turned off', function () {
    Payroll::setUp($this->company, $this->owner);
    $this->modules->enable(PlanningModule::KEY, $this->company, $this->owner);

    expect($this->modules->disableCheck(PayrollModule::KEY, $this->company)->allowed())->toBeFalse();

    Livewire::test(CompanyModulesTab::class, ['company' => $this->company])
        ->call('ask', PlanningModule::KEY, 'disable')
        ->assertSee('Pass, mallar och avdelningar finns kvar om modulen slås på igen.')
        ->set('typedName', 'planering')
        ->call('confirm')
        ->assertDispatched('toast', message: 'Modulen Planering är avstängd. Inga uppgifter har tagits bort.');

    CurrentCompany::set($this->company);
    expect(fn () => app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#e4572e']))->toThrow(ModuleDisabledException::class);
    $this->get(route('planning.schedule'))->assertNotFound();
});

dataset('planning roles', [
    'owner' => ['owner', ['view', 'manage', 'attest']],
    'admin' => ['admin', ['view', 'manage', 'attest']],
    'payroll admin' => ['payroll_admin', ['view', 'manage', 'attest']],
    'scheduler' => ['scheduler', ['view', 'manage']],
    'accountant' => ['accountant', []],
    'bookkeeper' => ['bookkeeper', []],
    'approver' => ['approver', []],
    'auditor' => ['auditor', []],
    'employee' => ['employee', []],
]);

it('allows and denies per role, and grants nothing while the module is off', function (string $role, array $allowed) {
    Payroll::setUp($this->company, $this->owner);
    $this->modules->enable(PlanningModule::KEY, $this->company, $this->owner);
    $user = $role === 'owner' ? $this->owner : Bookkeeping::member($this->company, $role);
    $gate = Gate::forUser($user);

    $abilities = array_filter(['view' => 'viewAny', 'manage' => 'manage', 'attest' => 'attest'], fn (string $ability): bool => $gate->allows($ability, [Shift::class, $this->company]));
    expect(array_keys($abilities))->toBe($allowed);

    $this->modules->disable(PlanningModule::KEY, $this->company, $this->owner, confirmed: true);
    expect($user->fresh()->hasTeamPermission($this->company, 'planning:view'))->toBeFalse();
})->with('planning roles');

it('offers the scheduler role only while Planering is on', function () {
    expect(app(ModuleRegistry::class)->moduleForRole('scheduler')?->key())->toBe(PlanningModule::KEY);
    Payroll::setUp($this->company, $this->owner);

    $scheduler = Bookkeeping::member($this->company, 'scheduler');
    expect($scheduler->hasTeamPermission($this->company, 'planning:manage'))->toBeFalse();

    $this->modules->enable(PlanningModule::KEY, $this->company, $this->owner);
    expect($scheduler->fresh()->hasTeamPermission($this->company, 'planning:manage'))->toBeTrue();
    $this->actingAs($scheduler->fresh())->get(route('planning.schedule'))->assertOk();
});

it('saves its settings under Moduler, keeping rule sources and merging new rules', function () {
    Payroll::setUp($this->company, $this->owner);
    $this->modules->enable(PlanningModule::KEY, $this->company, $this->owner);

    Livewire::test(PlanningSettingsForm::class, ['company' => $this->company])
        ->assertSee('Arbetstidslagen (1982:673) 13 §')
        ->set('settings.time_step_minutes', 30)
        ->set('settings.working_time_rules.daily_rest.value', 600)
        ->set('settings.working_time_rules.weekly_rest.enabled', false)
        ->set('settings.minors_enabled', true)
        ->set('settings.sales_account_to', 3999)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Inställningarna för planering är sparade.');

    $settings = PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $this->company));

    expect($settings['time_step_minutes'])->toBe(30)
        ->and($settings['working_time_rules']['daily_rest'])->toMatchArray(['value' => 600, 'enabled' => true, 'source' => 'Arbetstidslagen (1982:673) 13 §', 'verified_by' => null])
        ->and($settings['working_time_rules']['weekly_rest']['enabled'])->toBeFalse()
        ->and($settings['minors_enabled'])->toBeTrue()
        ->and($settings['sales_account_to'])->toBe(3999);

    Livewire::test(PlanningSettingsForm::class, ['company' => $this->company])
        ->set('settings.time_step_minutes', 7)
        ->call('save')
        ->assertHasErrors('settings.time_step_minutes');
});

it('refuses its settings to someone who cannot plan', function () {
    Payroll::setUp($this->company, $this->owner);
    $this->modules->enable(PlanningModule::KEY, $this->company, $this->owner);
    $this->actingAs(Bookkeeping::member($this->company, 'accountant'));

    Livewire::test(PlanningSettingsForm::class, ['company' => $this->company])->assertForbidden();
});

it('shows the settings on Planering → Inställningar, with the pay fields only for those who attest', function () {
    Payroll::setUp($this->company, $this->owner);
    $this->modules->enable(PlanningModule::KEY, $this->company, $this->owner);

    $this->get(route('planning.settings'))->assertOk()->assertSee('Regler, aviseringar och lön')->assertSee('Kontrolleras inte i schemat ännu');

    $scheduler = Bookkeeping::member($this->company, 'scheduler');
    $this->actingAs($scheduler)->get(route('planning.settings'))->assertOk()->assertSee('Bara den som attesterar tid till lönen kan ändra de här.');

    Livewire::actingAs($scheduler)->test(PlanningSettingsForm::class, ['company' => $this->company->fresh()])
        ->set('settings.attest_tolerance_minutes', 30)
        ->set('settings.time_step_minutes', 30)
        ->call('save')
        ->assertHasNoErrors();

    $settings = PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $this->company));
    expect($settings['attest_tolerance_minutes'])->toBe(5)->and($settings['time_step_minutes'])->toBe(30);

    Livewire::actingAs($payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin'))->test(PlanningSettingsForm::class, ['company' => $this->company->fresh()])
        ->set('settings.attest_tolerance_minutes', 10)
        ->call('save')
        ->assertHasNoErrors();

    expect(PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $this->company))['attest_tolerance_minutes'])->toBe(10);

    $this->actingAs(Bookkeeping::member($this->company, 'accountant'))->get(route('planning.settings'))->assertDontSee('Regler, aviseringar och lön');
});
