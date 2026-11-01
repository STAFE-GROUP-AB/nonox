<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Livewire\Accounting\Reports;
use App\Livewire\Invoicing\Customers;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Livewire\OverviewPage;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Support\Tenancy\CurrentCompany;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

it('refuses a request from a page rendered for another company than the user has switched to', function () {
    [$owner, $first] = Bookkeeping::company();
    CurrentCompany::set($first);
    app(SaveCustomer::class)($owner, $first, ['name' => 'Kund i första bolaget', 'payment_terms_days' => 30]);

    $second = User::factory()->withCompany()->create()->currentCompany();
    $second->users()->attach($owner, ['role' => 'owner']);

    $this->actingAs($owner);
    $page = Livewire::test(Customers::class)->assertSee('Kund i första bolaget');

    $owner->switchCompany($second);

    $page->call('$refresh')->assertNotFound();
});

it('keeps working while the user stays in the same company', function () {
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    $this->actingAs($owner);

    Livewire::test(Customers::class)->call('$refresh')->assertOk();
});

it('refuses a page kept open after the user lost the role it was opened with', function () {
    [$owner, $company] = Bookkeeping::company();
    $accountant = Bookkeeping::member($company, 'accountant');
    CurrentCompany::set($company);
    $this->actingAs($accountant);

    $page = Livewire::test(Reports::class)->assertOk();
    $company->users()->updateExistingPivot($accountant->id, ['role' => 'approver']);

    $page->call('$refresh')->assertNotFound();
});

it('refuses a module page kept open after the module was switched off', function () {
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);
    $modules = app(ModuleManager::class);
    $modules->enable(StaffLedgerModule::KEY, $company, $owner);
    $this->actingAs($owner);

    $page = Livewire::test(OverviewPage::class)->assertOk();
    $modules->disable(StaffLedgerModule::KEY, $company, $owner, confirmed: true);

    $page->call('$refresh')->assertNotFound();
});
