<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Livewire\CompanyModulesTab;
use App\Modules\ModuleDisabledException;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use App\Providers\JetstreamServiceProvider;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Modules\DummyModule;

beforeEach(function () {
    DummyModule::$blockers = [];
    DummyModule::$warnings = [];
    DummyModule::$enabledCount = 0;

    app(ModuleRegistry::class)->register(new DummyModule);
    app(ModuleRegistry::class)->register(new DummyModule('extra', ['dummy']));
    (new JetstreamServiceProvider(app()))->configurePermissions();

    Route::middleware(['web', 'auth', 'module:dummy'])->get('/dummy-test', fn () => 'Modulsidan')->name('dummy.index');
    // A route added after boot is not found by name until the lookup is rebuilt.
    Route::getRoutes()->refreshNameLookups();

    [$this->owner, $this->companyA] = Bookkeeping::company();
    [$this->otherOwner, $this->companyB] = Bookkeeping::company();
    $this->modules = app(ModuleManager::class);
    $this->actingAs($this->owner);
});

afterEach(function () {
    // Jetstream keeps roles in a static list; put back the roles without the test modules.
    Jetstream::$roles = [];
    app()->instance(ModuleRegistry::class, new ModuleRegistry);
    (new JetstreamServiceProvider(app()))->configurePermissions();
});

it('is off until turned on, per company, and audits every change', function () {
    expect($this->modules->isEnabled('dummy', $this->companyA))->toBeFalse();

    $this->modules->enable('dummy', $this->companyA, $this->owner);
    $this->modules->enable('dummy', $this->companyA, $this->owner);

    expect($this->modules->isEnabled('dummy', $this->companyA))->toBeTrue()
        ->and($this->modules->isEnabled('dummy', $this->companyB))->toBeFalse()
        ->and($this->modules->isEnabled('dummy', $this->companyA->id))->toBeTrue()
        ->and($this->modules->companiesWith('dummy'))->toBe([$this->companyA->id])
        ->and(DummyModule::$enabledCount)->toBe(2)
        ->and(CurrentCompany::run($this->companyA, fn () => AuditLog::query()->where('action', 'module.enabled')->count()))->toBe(2);

    $this->modules->disable('dummy', $this->companyA, $this->owner);

    expect($this->modules->isEnabled('dummy', $this->companyA))->toBeFalse()
        ->and(CurrentCompany::run($this->companyA, fn () => AuditLog::query()->where('action', 'module.disabled')->sole()->payload))
        ->toMatchArray(['module' => 'dummy', 'before' => true, 'after' => false]);
});

it('answers 404 on module routes, hides the menu and refuses actions where it is off', function () {
    $this->modules->enable('dummy', $this->companyA, $this->owner);

    $this->get('/dummy-test')->assertOk()->assertSee('Modulsidan');
    $this->get(route('dashboard'))->assertSee('Modul dummy');
    expect(fn () => CurrentCompany::run($this->companyA, fn () => $this->modules->ensureEnabled('dummy')))->not->toThrow(ModuleDisabledException::class);

    $this->actingAs($this->otherOwner);
    $this->get('/dummy-test')->assertNotFound();
    $this->get(route('dashboard'))->assertDontSee('Modul dummy');
    expect(fn () => CurrentCompany::run($this->companyB, fn () => $this->modules->ensureEnabled('dummy')))->toThrow(ModuleDisabledException::class);
});

it('gives module permissions only while the module is on, except archive access', function () {
    $admin = Bookkeeping::member($this->companyA, 'admin');
    $auditor = Bookkeeping::member($this->companyA, 'auditor');

    expect($admin->hasTeamPermission($this->companyA, 'dummy:view'))->toBeFalse()
        ->and($this->owner->hasTeamPermission($this->companyA, 'dummy:view'))->toBeFalse()
        ->and($auditor->hasTeamPermission($this->companyA, 'dummy:archive-view'))->toBeTrue()
        ->and($admin->hasTeamPermission($this->companyA, 'vouchers:view'))->toBeTrue();

    $this->modules->enable('dummy', $this->companyA, $this->owner);

    expect($admin->hasTeamPermission($this->companyA, 'dummy:view'))->toBeTrue()
        ->and($admin->hasTeamPermission($this->companyA, 'dummy:manage'))->toBeFalse()
        ->and($this->owner->hasTeamPermission($this->companyA, 'dummy:view'))->toBeTrue()
        ->and($this->owner->hasTeamPermission($this->companyA, 'dummy:manage'))->toBeFalse()
        ->and($this->owner->hasTeamPermission($this->companyA, 'vouchers:view'))->toBeTrue();
});

it('offers module roles only while the module is on', function () {
    $roles = fn (): array => collect(Livewire::test('teams.team-member-manager', ['team' => $this->companyA])->instance()->getRolesProperty())->pluck('key')->all();

    expect($roles())->not->toContain('dummy_admin')
        ->and(fn () => Livewire::test('teams.team-member-manager', ['team' => $this->companyA])
            ->set('addTeamMemberForm', ['email' => 'ny@exempel.test', 'role' => 'dummy_admin'])
            ->call('addTeamMember')
            ->assertHasErrors(['role']))->not->toThrow(Exception::class);

    $this->modules->enable('dummy', $this->companyA, $this->owner);

    expect($roles())->toContain('dummy_admin');
});

it('stops on blockers and asks for confirmation of warnings', function () {
    $this->modules->enable('dummy', $this->companyA, $this->owner);

    DummyModule::$blockers = ['En lönekörning är inte bokförd.'];
    expect(fn () => $this->modules->disable('dummy', $this->companyA, $this->owner, confirmed: true))->toThrow(ValidationException::class, 'En lönekörning är inte bokförd.');

    DummyModule::$blockers = [];
    DummyModule::$warnings = ['Anställda förlorar åtkomsten.'];
    expect(fn () => $this->modules->disable('dummy', $this->companyA, $this->owner))->toThrow(ValidationException::class, 'Bekräfta');

    $this->modules->disable('dummy', $this->companyA, $this->owner, confirmed: true);
    expect($this->modules->isEnabled('dummy', $this->companyA))->toBeFalse();
});

it('respects dependencies both ways', function () {
    expect(fn () => $this->modules->enable('extra', $this->companyA, $this->owner))->toThrow(ValidationException::class, 'måste slås på först');

    $this->modules->enable('dummy', $this->companyA, $this->owner);
    $this->modules->enable('extra', $this->companyA, $this->owner);

    expect(fn () => $this->modules->disable('dummy', $this->companyA, $this->owner, confirmed: true))->toThrow(ValidationException::class, 'Stäng av den först');
});

it('lets only those with modules:manage turn modules on', function () {
    expect(fn () => $this->modules->enable('dummy', $this->companyA, Bookkeeping::member($this->companyA, 'accountant')))->toThrow(AuthorizationException::class);
});

describe('the Moduler tab', function () {
    it('turns a module on and off with confirmation, typing the name when there are warnings', function () {
        $this->get(route('teams.show', ['company' => $this->companyA->id, 'flik' => 'moduler']))->assertOk()->assertSee('Dummy')->assertSee('Inaktiv');

        Livewire::test(CompanyModulesTab::class, ['company' => $this->companyA])
            ->call('ask', 'dummy', 'enable')
            ->assertSee('slås på för')
            ->call('confirm')
            ->assertDispatched('toast', message: 'Modulen Dummy är påslagen.');

        DummyModule::$warnings = ['Anställda förlorar åtkomsten.'];

        Livewire::test(CompanyModulesTab::class, ['company' => $this->companyA])
            ->call('ask', 'dummy', 'disable')
            ->assertSee('Anställda förlorar åtkomsten.')
            ->call('confirm')
            ->assertHasErrors('typedName')
            ->set('typedName', 'dummy')
            ->call('confirm')
            ->assertDispatched('toast', message: 'Modulen Dummy är avstängd. Inga uppgifter har tagits bort.');

        expect($this->modules->isEnabled('dummy', $this->companyA))->toBeFalse();
    });

    it('shows blockers and keeps the module on', function () {
        $this->modules->enable('dummy', $this->companyA, $this->owner);
        DummyModule::$blockers = ['En lönekörning är inte bokförd.'];

        Livewire::test(CompanyModulesTab::class, ['company' => $this->companyA])
            ->call('ask', 'dummy', 'disable')
            ->assertSee('En lönekörning är inte bokförd.')
            ->assertDontSee('Stäng av</button>', escape: false);

        expect($this->modules->isEnabled('dummy', $this->companyA))->toBeTrue();
    });

    it('is not shown to members without modules:manage', function () {
        $this->actingAs(Bookkeeping::member($this->companyA, 'accountant'));

        $this->get(route('teams.show', ['company' => $this->companyA->id, 'flik' => 'moduler']))->assertOk()->assertDontSee('Moduler är tillägg');
    });
});
