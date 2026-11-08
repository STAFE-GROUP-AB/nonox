<?php

declare(strict_types=1);

use App\Modules\Agreements\AgreementsModule;
use App\Modules\InvalidModule;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use App\Modules\Payroll\PayrollModule;
use App\Providers\JetstreamServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Laravel\Jetstream\Jetstream;
use Tests\Support\Bookkeeping;
use Tests\Support\Modules\DummyModule;
use Tests\Support\Modules\TestRegistry;
use Tests\Support\Modules\ThirdPartyDummyModule;

/*
 * ADR-079 §2.2: the registry refuses modules that would replace another
 * module or reach outside their own permissions and roles. These rules hold
 * for our own modules too; a third-party module (`vendor.name`) must in
 * addition keep every permission and role inside its own key.
 */

it('registers every first-party module, and only those, under its own key', function () {
    $keys = array_keys(app(ModuleRegistry::class)->all());
    sort($keys);

    expect($keys)->toBe(ModuleRegistry::FIRST_PARTY);
});

it('refuses a key that is already registered instead of replacing the module', function () {
    $registry = TestRegistry::make(['dummy']);
    $registry->register(new DummyModule);

    expect(fn () => $registry->register(new DummyModule))->toThrow(InvalidModule::class, 'Module dummy is already registered.')
        ->and(fn () => $registry->register(new DummyModule(PayrollModule::KEY, permissions: [], roles: [], rolePermissions: [])))
        ->toThrow(InvalidModule::class, 'Module payroll is already registered.')
        ->and($registry->get(PayrollModule::KEY))->toBeInstanceOf(PayrollModule::class);
});

it('refuses a module that claims a core permission', function () {
    $registry = TestRegistry::make(['dummy']);

    expect(fn () => $registry->register(new DummyModule(permissions: ['dummy:view', 'vouchers:post'], rolePermissions: [])))
        ->toThrow(InvalidModule::class, 'Module dummy claims the core permission vouchers:post.')
        ->and(fn () => $registry->register(new ThirdPartyDummyModule(permissions: ['vouchers:post'], roles: [], rolePermissions: [], archivePermissions: [])))
        ->toThrow(InvalidModule::class, 'Module acme.dummy claims the core permission vouchers:post.')
        ->and($registry->has('dummy'))->toBeFalse()
        ->and($registry->has('acme.dummy'))->toBeFalse()
        ->and($registry->moduleForPermission('vouchers:post'))->toBeNull();
});

it('refuses a module that claims another module\'s permission', function () {
    $registry = TestRegistry::make(['dummy']);

    expect(fn () => $registry->register(new DummyModule(permissions: ['payroll:view-sensitive'], rolePermissions: [])))
        ->toThrow(InvalidModule::class, 'Module dummy claims payroll:view-sensitive, which belongs to module payroll.')
        ->and($registry->moduleForPermission('payroll:view-sensitive'))->toBeInstanceOf(PayrollModule::class);
});

it('refuses a role key that is a core role or another module\'s role', function (string $role) {
    $registry = TestRegistry::make(['dummy']);

    expect(fn () => $registry->register(new DummyModule(roles: [$role => 'Kapad'], rolePermissions: [])))
        ->toThrow(InvalidModule::class)
        ->and($registry->has('dummy'))->toBeFalse();
})->with(['owner', 'admin', 'auditor', 'payroll_admin']);

it('refuses role permissions and archive permissions the module does not add itself', function () {
    $registry = TestRegistry::make(['dummy']);

    expect(fn () => $registry->register(new DummyModule(rolePermissions: ['admin' => ['dummy:view', 'vouchers:post']])))
        ->toThrow(InvalidModule::class, 'Module dummy gives vouchers:post to role admin, but does not add that permission.')
        ->and(fn () => $registry->register(new DummyModule(rolePermissions: ['admin' => ['*']])))
        ->toThrow(InvalidModule::class, 'Module dummy gives * to role admin, but does not add that permission.')
        ->and(fn () => $registry->register(new ThirdPartyDummyModule(archivePermissions: ['acme.dummy:view', 'payroll:archive-view'])))
        ->toThrow(InvalidModule::class, 'Module acme.dummy lists payroll:archive-view as an archive permission, but does not add that permission.');
});

it('requires a vendor.name key for a module that is not first-party', function (string $key) {
    expect(fn () => app(ModuleRegistry::class)->register(new ThirdPartyDummyModule($key)))->toThrow(InvalidModule::class)
        ->and(app(ModuleRegistry::class)->has($key))->toBeFalse();
})->with(['dummy', 'Acme.Dummy', 'acme.', '.dummy', 'acme.dummy.extra', 'acme dummy', 'acme.'.str_repeat('x', 50)]);

it('keeps a third-party module\'s permissions inside its own key', function (string $permission) {
    expect(fn () => app(ModuleRegistry::class)->register(new ThirdPartyDummyModule(permissions: ['acme.dummy:view', $permission], roles: [], rolePermissions: [], archivePermissions: [])))
        ->toThrow(InvalidModule::class)
        ->and(app(ModuleRegistry::class)->has('acme.dummy'))->toBeFalse();
})->with(['view', 'crm:view', 'acme.other:view', 'acme.dummyx:view', 'acme.dummy', 'self:acme']);

it('refuses a third-party role named owner or outside its own key', function (string $role) {
    expect(fn () => app(ModuleRegistry::class)->register(new ThirdPartyDummyModule(roles: [$role => 'Ägare'], rolePermissions: [])))
        ->toThrow(InvalidModule::class)
        ->and(app(ModuleRegistry::class)->has('acme.dummy'))->toBeFalse()
        ->and(app(ModuleRegistry::class)->moduleForRole($role))->toBeNull();
})->with(['owner', 'admin', 'acme_dummy_admin', 'acme.other:admin']);

it('lets a third-party module give its permissions only to core roles and its own roles', function () {
    expect(fn () => app(ModuleRegistry::class)->register(new ThirdPartyDummyModule(rolePermissions: ['payroll_admin' => ['acme.dummy:view']])))
        ->toThrow(InvalidModule::class, 'Module acme.dummy gives permissions to role payroll_admin, which is neither a core role nor its own.');

    app(ModuleRegistry::class)->register(new ThirdPartyDummyModule);

    expect(app(ModuleRegistry::class)->get('acme.dummy'))->toBeInstanceOf(ThirdPartyDummyModule::class);
});

it('still lets a first-party module give its permissions to another module\'s role', function () {
    // NONOX Avtal gives the payroll administrator its permissions (JetstreamServiceProvider).
    expect(app(ModuleRegistry::class)->get(AgreementsModule::KEY)->rolePermissions())->toHaveKey('payroll_admin');
});

it('checks modules registered before boot when the application has booted, and removes a refused one', function () {
    $registry = TestRegistry::make(['dummy']);
    $booted = app();

    // While providers register, translations do not exist yet: only the key is checked.
    Container::setInstance(new Application(base_path()));

    try {
        $registry->register(new DummyModule(permissions: ['vouchers:post'], rolePermissions: []));
        $registry->register(new ThirdPartyDummyModule(permissions: ['acme.dummy:view', 'crm:view'], roles: [], rolePermissions: [], archivePermissions: []));
    } finally {
        Container::setInstance($booted);
    }

    expect($registry->has('dummy'))->toBeTrue()
        ->and(fn () => $registry->verify())->toThrow(InvalidModule::class, 'Module dummy claims the core permission vouchers:post.')
        ->and($registry->has('dummy'))->toBeFalse()
        ->and($registry->moduleForPermission('vouchers:post'))->toBeNull()
        // The modules after the refused one are still checked on the next run.
        ->and(fn () => $registry->verify())->toThrow(InvalidModule::class, 'Module acme.dummy claims crm:view, which belongs to module crm.')
        ->and($registry->has('acme.dummy'))->toBeFalse();

    // The booted application has already checked every installed module.
    app(ModuleRegistry::class)->verify();
});

describe('a third-party module that follows the rules', function () {
    beforeEach(function () {
        app(ModuleRegistry::class)->register(new ThirdPartyDummyModule);
        (new JetstreamServiceProvider(app()))->configurePermissions();

        [$this->owner, $this->company] = Bookkeeping::company();
        $this->modules = app(ModuleManager::class);
    });

    afterEach(function () {
        // Jetstream keeps roles in a static list; put back the roles without the test module.
        Jetstream::$roles = [];
        app()->instance(ModuleRegistry::class, new ModuleRegistry);
        (new JetstreamServiceProvider(app()))->configurePermissions();
    });

    it('works like a first-party module, and leaves the core roles alone', function () {
        $acmeAdmin = Bookkeeping::member($this->company, 'acme.dummy:admin');
        $admin = Bookkeeping::member($this->company, 'admin');

        expect($acmeAdmin->hasTeamPermission($this->company, 'acme.dummy:manage'))->toBeFalse();

        $this->modules->enable('acme.dummy', $this->company, $this->owner);

        expect($acmeAdmin->hasTeamPermission($this->company, 'acme.dummy:manage'))->toBeTrue()
            ->and($acmeAdmin->hasTeamPermission($this->company, 'vouchers:post'))->toBeFalse()
            ->and($admin->hasTeamPermission($this->company, 'acme.dummy:view'))->toBeTrue()
            ->and($admin->hasTeamPermission($this->company, 'acme.dummy:manage'))->toBeFalse()
            ->and($this->owner->hasTeamPermission($this->company, 'acme.dummy:manage'))->toBeTrue()
            ->and($this->owner->hasTeamPermission($this->company, 'vouchers:post'))->toBeTrue()
            ->and(Jetstream::findRole('admin')?->permissions)->not->toContain('*');
    });
});
