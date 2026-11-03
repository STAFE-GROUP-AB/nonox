<?php

declare(strict_types=1);

use App\Actions\Hq\SyncWaitlistEntryToOperator;
use App\Models\Customer;
use App\Models\InstanceSetting;
use App\Models\WaitlistEntry;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Person;
use App\Support\Instance\InstanceSettings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * AGENTS.md §13.2 for the operator company (ADR-044): the sync writes into
 * the operator's registers by design, and nowhere else. A tenant never sees
 * the operator's customers or prospects (its own company among them, or
 * another tenant), and the operator's users see them as their own data.
 */

beforeEach(function () {
    Mail::fake();

    [$this->operatorOwner, $this->operator] = Bookkeeping::company();
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();

    InstanceSetting::query()->create(['key' => InstanceSettings::OPERATOR, 'value' => $this->operator->id]);
    InstanceSettings::current()->forget();

    [$this->ownerA, $this->companyA] = Bookkeeping::company();
    $this->companyA->forceFill(['name' => 'Alfa Tenant AB', 'legal_name' => 'Alfa Tenant AB'])->save();
    Crm::enable($this->companyA, $this->ownerA);
    CurrentCompany::forget();

    [$this->ownerB, $this->companyB] = Bookkeeping::company();
    $this->companyB->forceFill(['name' => 'Beta Tenant AB', 'legal_name' => 'Beta Tenant AB'])->save();

    $entry = WaitlistEntry::factory()->create(['company_name' => 'Köbolaget Prospekt AB', 'name' => 'Kim Kö']);
    app(SyncWaitlistEntryToOperator::class)($entry, $this->operator);

    $this->betaCustomer = CurrentCompany::run($this->operator, fn () => Customer::query()->where('name', 'Beta Tenant AB')->sole());
    $this->prospect = CurrentCompany::run($this->operator, fn () => Account::query()->whereNull('customer_id')->sole());
});

it('writes the copies into the operator company only', function () {
    expect(CurrentCompany::run($this->operator, fn () => Customer::query()->pluck('name')->sort()->values()->all()))->toBe(['Alfa Tenant AB', 'Beta Tenant AB'])
        ->and(CurrentCompany::run($this->companyA, fn () => [Customer::query()->count(), Account::query()->count(), Person::query()->count()]))->toBe([0, 0, 0])
        ->and(CurrentCompany::run($this->companyB, fn () => Customer::query()->count()))->toBe(0);
});

it('never shows a tenant the operator\'s customers or prospects; their ids answer 404', function () {
    $this->actingAs($this->ownerA);

    $this->get(route('invoicing.customers'))->assertOk()
        ->assertDontSee('Beta Tenant AB')
        ->assertDontSee('Köbolaget Prospekt AB');
    $this->get(route('crm.people'))->assertOk()->assertDontSee('Kim Kö');
    $this->get(route('crm.accounts.show', $this->prospect))->assertNotFound();
    $this->get(route('invoicing.customers', ['redigera' => $this->betaCustomer->id]))->assertOk()->assertDontSee('Beta Tenant AB');

    expect(Customer::query()->whereKey($this->betaCustomer->id)->exists())->toBeFalse()
        ->and(Account::query()->whereKey($this->prospect->id)->exists())->toBeFalse();
});

it('shows the operator\'s users the copies as their own customers and prospects', function () {
    $this->actingAs($this->operatorOwner);
    $this->operatorOwner->switchCompany($this->operator);

    $this->get(route('invoicing.customers'))->assertOk()
        ->assertSee('Alfa Tenant AB')
        ->assertSee('Beta Tenant AB')
        ->assertSee('Köbolaget Prospekt AB');
    $this->get(route('crm.accounts.show', $this->prospect))->assertOk()->assertSee('Kim Kö');
});
