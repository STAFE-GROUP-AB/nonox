<?php

declare(strict_types=1);

use App\Models\VoucherSeries;
use App\Modules\Livewire\CompanyModulesTab;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Livewire\PayrollSettingsForm;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\PayrollSettings;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    [$this->otherOwner, $this->other] = Bookkeeping::company();
    $this->modules = app(ModuleManager::class);
    $this->actingAs($this->owner);
});

it('is installed but off, and its pages answer 404 until turned on', function () {
    $this->get(route('payroll.index'))->assertNotFound();
    $this->get(route('dashboard'))->assertDontSee(route('payroll.index'), escape: false);

    $this->modules->enable(PayrollModule::KEY, $this->company, $this->owner);

    $this->get(route('payroll.index'))->assertOk()->assertSee('Kom igång med lön');
    $this->get(route('dashboard'))->assertSee(route('payroll.index'), escape: false);

    $this->actingAs($this->otherOwner)->get(route('payroll.index'))->assertNotFound();
});

it('seeds its settings once and keeps series L', function () {
    $this->modules->enable(PayrollModule::KEY, $this->company, $this->owner);
    $this->modules->updateSettings(PayrollModule::KEY, $this->company, $this->owner, ['default_payment_day' => 27]);
    $this->modules->disable(PayrollModule::KEY, $this->company, $this->owner, confirmed: true);
    $this->modules->enable(PayrollModule::KEY, $this->company, $this->owner);

    expect($this->modules->settings(PayrollModule::KEY, $this->company))
        ->toEqual([...PayrollSettings::DEFAULTS, 'default_payment_day' => 27])
        ->and(CurrentCompany::run($this->company, fn () => VoucherSeries::query()->where('code', 'L')->exists()))->toBeTrue();
});

it('gives the default permissions per role, and sensitive data to no owner', function () {
    $this->modules->enable(PayrollModule::KEY, $this->company, $this->owner);
    $admin = Bookkeeping::member($this->company, 'admin');
    $accountant = Bookkeeping::member($this->company, 'accountant');
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');
    $employee = Bookkeeping::member($this->company, 'employee');

    expect($this->owner->hasTeamPermission($this->company, 'payroll:run'))->toBeTrue()
        ->and($this->owner->hasTeamPermission($this->company, 'payroll:view-sensitive'))->toBeFalse()
        ->and($payrollAdmin->hasTeamPermission($this->company, 'payroll:view-sensitive'))->toBeTrue()
        ->and($admin->hasTeamPermission($this->company, 'payroll:approve'))->toBeTrue()
        ->and($admin->hasTeamPermission($this->company, 'payroll:run'))->toBeFalse()
        ->and($accountant->hasTeamPermission($this->company, 'payroll:post'))->toBeTrue()
        ->and($bookkeeper->hasTeamPermission($this->company, 'payroll:view'))->toBeFalse()
        ->and($employee->hasTeamPermission($this->company, 'self:payslips'))->toBeTrue()
        ->and($employee->hasTeamPermission($this->company, 'payroll:view'))->toBeFalse()
        ->and($employee->hasTeamPermission($this->company, 'vouchers:view'))->toBeFalse();

    $this->modules->disable(PayrollModule::KEY, $this->company, $this->owner, confirmed: true);

    expect($this->owner->hasTeamPermission($this->company, 'payroll:run'))->toBeFalse()
        ->and($this->owner->hasTeamPermission($this->company, 'payroll:archive-view'))->toBeTrue()
        ->and($employee->hasTeamPermission($this->company, 'self:payslips'))->toBeFalse();
});

it('is turned on and off from the Moduler tab, typing Lön to confirm', function () {
    Livewire::test(CompanyModulesTab::class, ['company' => $this->company])
        ->assertSee('Lön')
        ->call('ask', PayrollModule::KEY, 'enable')
        ->call('confirm')
        ->assertDispatched('toast', message: 'Modulen Lön är påslagen.');

    Livewire::test(CompanyModulesTab::class, ['company' => $this->company])
        ->call('ask', PayrollModule::KEY, 'disable')
        ->assertSee('Semestersaldon och semesterlöneskuld finns kvar i bokföringen.')
        ->set('typedName', 'lön')
        ->call('confirm')
        ->assertDispatched('toast', message: 'Modulen Lön är avstängd. Inga uppgifter har tagits bort.');
});

it('saves its settings with a toast and checks the values', function () {
    $this->modules->enable(PayrollModule::KEY, $this->company, $this->owner);

    Livewire::test(PayrollSettingsForm::class, ['company' => $this->company])
        ->set('settings.default_payment_day', 40)
        ->call('save')
        ->assertHasErrors('settings.default_payment_day')
        ->set('settings.default_payment_day', 25)
        ->set('settings.vacation_rule_default', 'PROCENTREGELN')
        ->call('save')
        ->assertDispatched('toast', message: 'Löneinställningarna är sparade.');

    expect($this->modules->settings(PayrollModule::KEY, $this->company)['vacation_rule_default'])->toBe('PROCENTREGELN');
});
