<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveCustomer;
use App\Livewire\Invoicing\Customers;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Livewire\AccountsPage;
use App\Modules\Crm\Livewire\CrmSettingsForm;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * CRM as a module (ADR-043): off by default, 404 while off, /kunder as the
 * plain register while off and the CRM while on, the Säljare role, the
 * settings, and an account for every customer.
 */

const CRM_PAGES = ['crm.activities', 'crm.people', 'crm.settings', 'crm.dashboard', 'crm.deals', 'crm.forecast'];

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

it('answers 404 until it is turned on, then shows every page with Swedish URLs', function () {
    foreach (CRM_PAGES as $route) {
        $this->get(route($route))->assertNotFound();
    }

    Crm::enable($this->company, $this->owner);

    foreach (CRM_PAGES as $route) {
        expect(parse_url(route($route), PHP_URL_PATH))->toStartWith('/kunder/');
        $this->get(route($route))->assertOk();
    }

    $this->get(route('dashboard'))->assertSee(route('crm.dashboard'), escape: false);
    expect(app(ModuleManager::class)->settings(CrmModule::KEY, $this->company))->toEqual(CrmSettings::DEFAULTS);

    [$otherOwner] = Bookkeeping::company();
    $this->actingAs($otherOwner)->get(route('crm.activities'))->assertNotFound();
});

it('keeps /kunder as the plain register while off, and shows the CRM list while on', function () {
    Crm::customer($this->company, $this->owner, 'Kontorshuset AB');

    $this->get(route('invoicing.customers'))->assertOk()
        ->assertSeeLivewire(Customers::class)
        ->assertDontSeeLivewire(AccountsPage::class)
        ->assertSee('Dem du fakturerar.');

    Crm::enable($this->company, $this->owner);

    $this->get(route('invoicing.customers'))->assertOk()
        ->assertSeeLivewire(AccountsPage::class)
        ->assertDontSeeLivewire(Customers::class)
        ->assertSee('Kontorshuset AB');

    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, confirmed: true);

    $this->get(route('invoicing.customers'))->assertOk()->assertSeeLivewire(Customers::class);
    expect(Account::query()->count())->toBe(1);
});

it('keeps the register\'s links working: ?redigera opens the customer\'s card, ?ny the form', function () {
    $customer = Crm::customer($this->company, $this->owner);
    Crm::enable($this->company, $this->owner);
    $account = Crm::accountFor($customer);

    $this->get(route('invoicing.customers', ['redigera' => $customer->id]))->assertRedirect(route('crm.accounts.show', $account));

    Livewire::withQueryParams(['ny' => 1])->test(AccountsPage::class)->assertSet('showForm', true);
});

it('gives every existing customer one account when turned on, and a new customer one while on', function () {
    $first = Crm::customer($this->company, $this->owner, 'Första AB', ['org_number' => '556036-0793']);
    $second = Crm::customer($this->company, $this->owner, 'Andra AB', ['email' => 'info@andra.example', 'city' => 'Lund']);

    Crm::enable($this->company, $this->owner);
    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, confirmed: true);
    Crm::enable($this->company, $this->owner);

    expect(Account::query()->count())->toBe(2)
        ->and(Crm::accountFor($first)->org_number)->toBe('556036-0793')
        ->and(Crm::accountFor($second)->only(['name', 'email', 'city']))->toBe(['name' => 'Andra AB', 'email' => 'info@andra.example', 'city' => 'Lund']);

    $third = Crm::customer($this->company, $this->owner, 'Tredje AB');
    expect(Crm::accountFor($third)->status->value)->toBe('PROSPECT');

    // A customer changed in the register changes its account.
    app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Tredje AB (nytt namn)', 'payment_terms_days' => 30, 'city' => 'Malmö'], $third);
    expect(Crm::accountFor($third)->only(['name', 'city']))->toBe(['name' => 'Tredje AB (nytt namn)', 'city' => 'Malmö']);
});

it('creates no account for a new customer while the module is off', function () {
    Crm::customer($this->company, $this->owner);

    expect(Account::query()->count())->toBe(0);
});

dataset('crm roles', [
    'owner' => ['owner', ['viewAny', 'manage', 'settings']],
    'admin' => ['admin', ['viewAny', 'manage', 'settings']],
    'accountant' => ['accountant', ['viewAny', 'manage', 'settings']],
    'bookkeeper' => ['bookkeeper', ['viewAny', 'manage', 'settings']],
    'auditor' => ['auditor', ['viewAny']],
    'approver' => ['approver', []],
    'säljare' => ['sales', ['viewAny', 'manage']],
]);

it('allows and denies per role, and grants nothing while the module is off', function (string $role, array $allowed) {
    Crm::enable($this->company, $this->owner);
    $user = $role === 'owner' ? $this->owner : Bookkeeping::member($this->company, $role);
    $gate = Gate::forUser($user);

    $abilities = array_values(array_filter(['viewAny', 'manage', 'settings'], fn (string $ability): bool => $gate->allows($ability, [Activity::class, $this->company])));
    expect($abilities)->toBe($allowed);

    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, confirmed: true);
    expect($user->fresh()->hasTeamPermission($this->company, 'crm:view'))->toBeFalse();
})->with('crm roles');

it('offers Säljare only while CRM is on, with the CRM and nothing in the ledger', function () {
    expect(app(ModuleRegistry::class)->moduleForRole('sales')?->key())->toBe(CrmModule::KEY);

    Crm::enable($this->company, $this->owner);
    $seller = Bookkeeping::member($this->company, 'sales');

    $this->actingAs($seller)->get(route('invoicing.customers'))->assertOk()->assertSeeLivewire(AccountsPage::class);
    $this->actingAs($seller)->get(route('crm.activities'))->assertOk();
    $this->actingAs($seller)->get(route('crm.settings'))->assertOk()->assertSee('Du kan inte ändra inställningarna');
    $this->actingAs($seller)->get(route('invoicing.invoices.index'))->assertForbidden();
    $this->actingAs($seller)->get(route('accounting.vouchers.index'))->assertForbidden();

    foreach (['invoices:view', 'invoices:create', 'vouchers:view', 'reports:view'] as $permission) {
        expect($seller->hasTeamPermission($this->company, $permission))->toBeFalse();
    }
});

it('saves the settings within their limits, for those with crm:settings', function () {
    Crm::enable($this->company, $this->owner);

    Livewire::test(CrmSettingsForm::class, ['company' => $this->company])
        ->set('settings.months_to_inactive', 6)
        ->set('settings.prune_after_months', 36)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect(CrmSettings::for($this->company))->toBe(['months_to_inactive' => 6, 'prune_after_months' => 36, 'suppression_review_years' => 3, 'win_back_months' => 6, 'agreement_reminder_days' => 60]);

    Livewire::test(CrmSettingsForm::class, ['company' => $this->company])
        ->set('settings.months_to_inactive', 0)
        ->set('settings.prune_after_months', 2)
        ->call('save')
        ->assertHasErrors(['settings.months_to_inactive', 'settings.prune_after_months']);

    $seller = Bookkeeping::member($this->company, 'sales');
    $this->actingAs($seller);
    Livewire::test(CrmSettingsForm::class, ['company' => $this->company])->assertForbidden();
});

it('runs the status rules every day in the schedule', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command);

    expect($events->contains(fn (?string $command): bool => str_contains((string) $command, 'crm:refresh-statuses')))->toBeTrue()
        ->and($events->contains(fn (?string $command): bool => str_contains((string) $command, 'crm:win-back')))->toBeTrue();
});
