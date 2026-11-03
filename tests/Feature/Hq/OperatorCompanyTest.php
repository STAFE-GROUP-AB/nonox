<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Actions\Hq\SyncCompanyToOperator;
use App\Actions\Hq\SyncWaitlistEntryToOperator;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Waitlist\ApproveWaitlistEntry;
use App\Actions\Waitlist\DeleteWaitlistEntry;
use App\Actions\Waitlist\JoinWaitlist;
use App\Domain\Company\LegalForm;
use App\Domain\Waitlist\WaitlistStatus;
use App\Livewire\Hq\Settings;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\InstanceSetting;
use App\Models\OperatorLink;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Domain\LegalBasis;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Person;
use App\Support\Instance\InstanceSettings;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * The operator company (ADR-044): every company in the installation becomes
 * a customer of the company that runs the service, every queue applicant a
 * CRM prospect, kept in step without duplicates, and erased again with the
 * queue entry unless someone has worked on it.
 */

function operatorSettings(array $values): void
{
    foreach ($values as $key => $value) {
        InstanceSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    InstanceSettings::current()->forget();
}

function operatorCompanyInput(array $overrides = []): array
{
    return [
        'name' => 'Kundbolaget AB',
        'org_number' => CompanyFactory::organisationNumber()->digits,
        'legal_form' => 'AB',
        'fiscal_year_start_month' => 1,
        'accounting_method' => 'FAKTURAMETODEN',
        'vat_registered' => true,
        'vat_period' => 'QUARTERLY',
        'reporting_framework' => 'K2',
        'framework_confirmed' => true,
        'f_skatt' => true,
        'employer_registered' => false,
        'address_line1' => 'Storgatan 1',
        'postal_code' => '111 22',
        'city' => 'Stockholm',
        ...$overrides,
    ];
}

/** The operator's customers, read inside the operator company. */
function operatorCustomers(Company $operator): Collection
{
    return CurrentCompany::run($operator, fn () => Customer::query()->orderBy('id')->get());
}

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    config(['nonox.hq_admins' => ['hq@example.test'], 'app.admin_email' => null, 'app.landing.hosted' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00'));

    [$this->operatorOwner, $this->operator] = Bookkeeping::company();
    $this->operator->forceFill(['name' => 'Driftbolaget AB'])->save();

    operatorSettings([InstanceSettings::OPERATOR => $this->operator->id]);
});

it('makes a new company a customer of the operator company, after the commit', function () {
    $user = User::factory()->create(['email' => 'agare@example.test', 'name' => 'Ägaren']);
    $input = operatorCompanyInput();

    $company = app(CreateCompany::class)->create($user, $input);

    $customers = operatorCustomers($this->operator);
    $link = OperatorLink::query()->where('source_company_id', $company->id)->sole();

    expect($customers)->toHaveCount(1)
        ->and($customers[0]->name)->toBe('Kundbolaget AB')
        ->and($customers[0]->org_number)->toBe($company->org_number)
        ->and($customers[0]->personal_number)->toBeNull()
        ->and($customers[0]->vat_number)->toBe($company->vat_number)
        ->and($customers[0]->email)->toBe('agare@example.test')
        ->and($customers[0]->address_line1)->toBe('Storgatan 1')
        ->and($customers[0]->city)->toBe('Stockholm')
        ->and($link->target_company_id)->toBe($this->operator->id)
        ->and($link->customer_id)->toBe($customers[0]->id)
        ->and($link->customer_created)->toBeTrue()
        ->and($link->status)->toBe(OperatorLink::SYNCED)
        ->and(json_encode($link->pushed))->not->toContain('Kundbolaget');

    // The company's own register is untouched, and the audit log of the instance has ids only.
    expect(CurrentCompany::run($company, fn () => Customer::query()->count()))->toBe(0);

    $audit = AuditLog::query()->where('action', 'hq.operator_customer_synced')->sole();
    expect($audit->company_id)->toBeNull()
        ->and($audit->payload)->toEqual(['source_company_id' => $company->id, 'target_company_id' => $this->operator->id, 'customer_id' => $customers[0]->id, 'outcome' => 'created']);
});

it('does nothing while no operator company is chosen, and never makes the operator, a test company or itself a customer', function () {
    operatorSettings([InstanceSettings::OPERATOR => null]);
    User::factory()->withCompany()->create();

    expect(OperatorLink::query()->count())->toBe(0);

    operatorSettings([InstanceSettings::OPERATOR => $this->operator->id]);
    $this->operator->forceFill(['name' => 'Driftbolaget Nytt Namn AB'])->save();
    Company::factory()->create(['is_test_company' => true]);

    expect(operatorCustomers($this->operator))->toHaveCount(0)
        ->and(app(SyncCompanyToOperator::class)($this->operator, $this->operator))->toBe(SyncCompanyToOperator::SKIPPED);
});

it('keeps a sole trader\'s personal number out of org_number and the VAT number, encrypted in personal_number', function () {
    $nine = '811228987';
    $personal = '811228-987'.Luhn::checkDigit($nine);

    $company = Company::factory()->create(['legal_form' => LegalForm::Ef, 'org_number' => $personal, 'vat_number' => 'SE'.str_replace('-', '', $personal).'01', 'name' => 'Firma Ägaren']);

    $customer = operatorCustomers($this->operator)->sole();
    $raw = DB::table('customers')->where('id', $customer->id)->value('personal_number');

    expect($customer->org_number)->toBeNull()
        ->and($customer->vat_number)->toBeNull()
        ->and($customer->personal_number)->toBe($personal)
        ->and($raw)->not->toContain('811228')
        ->and($company->org_number)->toBe($personal);
});

it('never makes two customers of one company, and links the one existing customer with the same organisation number', function () {
    $number = CompanyFactory::organisationNumber();
    $existing = app(SaveCustomer::class)($this->operatorOwner, $this->operator, ['name' => 'Gammal kund AB', 'org_number' => $number->formatted(), 'payment_terms_days' => 20, 'email' => 'faktura@example.test']);

    $company = Company::factory()->create(['org_number' => $number->formatted(), 'name' => 'Ny Kund AB', 'legal_name' => 'Ny Kund AB']);

    app(SyncCompanyToOperator::class)($company->fresh(), $this->operator);
    app(SyncCompanyToOperator::class)($company->fresh(), $this->operator);

    $customers = operatorCustomers($this->operator);

    expect($customers)->toHaveCount(1)
        ->and($customers[0]->id)->toBe($existing->id)
        // Linked as it is: the operator's own data is not overwritten at linking.
        ->and($customers[0]->name)->toBe('Gammal kund AB')
        ->and($customers[0]->payment_terms_days)->toBe(20)
        ->and(OperatorLink::query()->sole()->customer_created)->toBeFalse();
});

it('pushes a rename and a new address, and keeps the operator\'s own edits of other fields', function () {
    $company = Company::factory()->create(['name' => 'Före AB', 'legal_name' => 'Före AB', 'city' => 'Lund']);
    $customer = operatorCustomers($this->operator)->sole();

    // The operator changes the invoice address by hand.
    app(SaveCustomer::class)($this->operatorOwner, $this->operator, [...$customer->only(['name', 'org_number', 'vat_number', 'city', 'country']), 'email' => 'ekonomi@example.test', 'payment_terms_days' => 10], $customer);

    $company->forceFill(['name' => 'Efter AB', 'legal_name' => 'Efter AB', 'city' => 'Malmö'])->save();

    $customer = operatorCustomers($this->operator)->sole();

    expect($customer->name)->toBe('Efter AB')
        ->and($customer->city)->toBe('Malmö')
        ->and($customer->email)->toBe('ekonomi@example.test')
        ->and($customer->payment_terms_days)->toBe(10);

    // Archived: inactive; taken out of the archive: active again. Never deleted.
    $company->forceFill(['archived_at' => now()])->save();
    expect(operatorCustomers($this->operator)->sole()->is_active)->toBeFalse();

    $company->forceFill(['archived_at' => null])->save();
    expect(operatorCustomers($this->operator)->sole()->is_active)->toBeTrue();
});

it('never writes again to a customer whose processing the operator has restricted', function () {
    $company = Company::factory()->create(['name' => 'Begränsad AB', 'legal_name' => 'Begränsad AB']);
    $customer = operatorCustomers($this->operator)->sole();
    CurrentCompany::run($this->operator, fn () => $customer->forceFill(['processing_restricted_at' => now()])->save());

    $company->forceFill(['name' => 'Nytt Namn AB', 'legal_name' => 'Nytt Namn AB'])->save();

    expect(operatorCustomers($this->operator)->sole()->name)->toBe('Begränsad AB');
});

it('sends the customers and the queue to other companies when HQ chooses them', function () {
    operatorSettings([InstanceSettings::OPERATOR => null]);
    [$customersOwner, $customersTarget] = Bookkeeping::company();
    operatorSettings([InstanceSettings::OPERATOR => $this->operator->id, InstanceSettings::CUSTOMERS_TARGET => $customersTarget->id]);

    $company = Company::factory()->create();

    expect(operatorCustomers($this->operator))->toHaveCount(0)
        ->and(operatorCustomers($customersTarget))->toHaveCount(1)
        ->and(OperatorLink::query()->sole()->target_company_id)->toBe($customersTarget->id)
        ->and(OperatorLink::query()->where('source_company_id', $customersTarget->id)->exists())->toBeFalse()
        ->and($company->id)->not->toBe($customersTarget->id);
});

it('adds the owner as a contact person under the agreement when CRM is on', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();

    $owner = User::factory()->create(['email' => 'ann@example.test', 'name' => 'Ann Ägare']);
    $company = Company::factory()->create(['user_id' => $owner->id]);

    CurrentCompany::run($this->operator, function () use ($company) {
        $customer = Customer::query()->sole();
        $account = Account::query()->where('customer_id', $customer->id)->sole();
        $person = Person::query()->sole();

        expect($person->email)->toBe('ann@example.test')
            ->and($person->legal_basis)->toBe(LegalBasis::Agreement)
            ->and(AccountPerson::query()->where('account_id', $account->id)->where('person_id', $person->id)->exists())->toBeTrue()
            ->and(OperatorLink::query()->where('source_company_id', $company->id)->sole()->crm_person_id)->toBe($person->id);
    });

    // A second sync adds nobody.
    app(SyncCompanyToOperator::class)($company->fresh(), $this->operator);
    expect(CurrentCompany::run($this->operator, fn () => Person::query()->count()))->toBe(1);
});

it('makes a queue applicant a CRM prospect with consent as the legal basis', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();
    $number = CompanyFactory::organisationNumber();

    app(JoinWaitlist::class)([
        'name' => 'Sara Sökande',
        'email' => 'Sara@Example.test',
        'phone' => '070-123 45 67',
        'company_name' => 'Sökbolaget AB',
        'org_number' => $number->digits,
        'industry' => 'bokforing-for-konsulter',
        'employees' => '1-4',
        'current_system' => 'inget',
        'consent' => true,
    ]);

    $entry = WaitlistEntry::query()->sole();
    $link = OperatorLink::query()->where('waitlist_entry_id', $entry->id)->sole();

    CurrentCompany::run($this->operator, function () use ($link, $number) {
        $account = Account::query()->sole();
        $person = Person::query()->sole();

        expect($account->id)->toBe($link->crm_account_id)
            ->and($account->name)->toBe('Sökbolaget AB')
            ->and($account->org_number)->toBe($number->formatted())
            ->and($account->source)->toBe('Kö')
            ->and($account->customer_id)->toBeNull()
            ->and($person->email)->toBe('sara@example.test')
            ->and($person->legal_basis)->toBe(LegalBasis::Consent)
            ->and($person->consent_at->toDateString())->toBe('2026-11-03')
            ->and($person->informed_at->toDateString())->toBe('2026-11-03')
            ->and(Customer::query()->count())->toBe(0);
    });

    expect($link->account_created)->toBeTrue()->and($link->person_created)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'hq.operator_prospect_synced')->sole()->payload)->not->toHaveKey('name');
});

it('keeps a personal number from the queue out of the CRM', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();
    $personal = '811228-987'.Luhn::checkDigit('811228987');

    $entry = WaitlistEntry::factory()->create(['org_number' => $personal]);
    app(SyncWaitlistEntryToOperator::class)($entry, $this->operator);

    expect(CurrentCompany::run($this->operator, fn () => Account::query()->sole()->org_number))->toBeNull();
});

it('skips the queue and says so in HQ while CRM is off in the target', function () {
    $entry = WaitlistEntry::factory()->create();

    expect(app(SyncWaitlistEntryToOperator::class)($entry, $this->operator))->toBe(SyncWaitlistEntryToOperator::CRM_OFF)
        ->and(OperatorLink::query()->count())->toBe(0);

    $admin = User::factory()->create(['email' => 'hq@example.test']);
    $this->actingAs($admin);

    Livewire::test(Settings::class)->assertSee('CRM är inte påslaget i företaget som köanmälningarna ska till');
});

it('respects the suppression list and Kontakta inte: nothing is created for them', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    app(SavePerson::class)($this->operatorOwner, $this->operator, ['name' => 'Nej Tack', 'email' => 'nej@example.test', 'legal_basis' => 'LEGITIMATE_INTEREST', 'do_not_contact' => true, 'do_not_contact_reason' => 'OBJECTION']);
    CurrentCompany::forget();

    $entry = WaitlistEntry::factory()->create(['email' => 'nej@example.test']);

    expect(app(SyncWaitlistEntryToOperator::class)($entry, $this->operator))->toBe(SyncWaitlistEntryToOperator::SUPPRESSED)
        ->and(CurrentCompany::run($this->operator, fn () => Account::query()->count()))->toBe(0)
        ->and(CurrentCompany::run($this->operator, fn () => Person::query()->count()))->toBe(1)
        ->and(OperatorLink::query()->sole()->status)->toBe(OperatorLink::SUPPRESSED);
});

it('turns the prospect into the customer when the approved applicant creates the company, without a duplicate', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();
    $admin = User::factory()->create(['email' => 'hq@example.test']);

    $entry = WaitlistEntry::factory()->create(['email' => 'ny@example.test', 'name' => 'Nina Ny', 'company_name' => 'Kö-namnet AB']);
    app(SyncWaitlistEntryToOperator::class)($entry, $this->operator);
    $accountId = OperatorLink::query()->sole()->crm_account_id;

    $user = app(ApproveWaitlistEntry::class)($admin, $entry);
    $company = app(CreateCompany::class)->create($user, operatorCompanyInput(['name' => 'Riktiga Namnet AB']));

    $link = OperatorLink::query()->sole();

    CurrentCompany::run($this->operator, function () use ($link, $accountId, $company, $entry) {
        $customer = Customer::query()->sole();
        $account = Account::query()->sole();
        $person = Person::query()->sole();

        expect($account->id)->toBe($accountId)
            ->and($account->customer_id)->toBe($customer->id)
            ->and($customer->name)->toBe('Riktiga Namnet AB')
            ->and($customer->org_number)->toBe($company->org_number)
            ->and($person->email)->toBe('ny@example.test')
            ->and($person->legal_basis)->toBe(LegalBasis::Agreement)
            ->and($link->source_company_id)->toBe($company->id)
            ->and($link->waitlist_entry_id)->toBe($entry->id)
            ->and($link->customer_id)->toBe($customer->id)
            ->and($link->customer_created)->toBeTrue();
    });

    // Deleting the entry later only unlinks: the customer and the person stay.
    app(DeleteWaitlistEntry::class)($admin, $entry->fresh());

    expect(OperatorLink::query()->sole()->waitlist_entry_id)->toBeNull()
        ->and(CurrentCompany::run($this->operator, fn () => [Customer::query()->count(), Account::query()->count(), Person::query()->count()]))->toBe([1, 1, 1]);
});

it('erases the prospect and the person when the queue entry is deleted', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();
    $admin = User::factory()->create(['email' => 'hq@example.test']);

    $entry = WaitlistEntry::factory()->create();
    app(SyncWaitlistEntryToOperator::class)($entry, $this->operator);

    $released = app(DeleteWaitlistEntry::class)($admin, $entry);

    expect($released)->toBe(['erased_accounts' => 1, 'erased_people' => 1, 'kept' => 0])
        ->and(OperatorLink::query()->count())->toBe(0)
        ->and(CurrentCompany::run($this->operator, fn () => [Account::query()->count(), Person::query()->count()]))->toBe([0, 0])
        ->and(AuditLog::query()->where('action', 'hq.operator_prospect_released')->exists())->toBeTrue();
});

it('reuses an existing account with the same organisation number, and leaves it when the entry is deleted', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    $number = CompanyFactory::organisationNumber();
    $existing = Crm::prospect($this->operator, $this->operatorOwner, 'Redan känt AB', ['org_number' => $number->formatted()]);
    CurrentCompany::forget();
    $admin = User::factory()->create(['email' => 'hq@example.test']);

    $entry = WaitlistEntry::factory()->create(['org_number' => $number->formatted()]);
    app(SyncWaitlistEntryToOperator::class)($entry, $this->operator);

    expect(OperatorLink::query()->sole()->crm_account_id)->toBe($existing->id)
        ->and(OperatorLink::query()->sole()->account_created)->toBeFalse();

    $released = app(DeleteWaitlistEntry::class)($admin, $entry);

    expect($released)->toBe(['erased_accounts' => 0, 'erased_people' => 1, 'kept' => 0])
        ->and(CurrentCompany::run($this->operator, fn () => [Account::query()->pluck('id')->all(), Person::query()->count()]))->toBe([[$existing->id], 0]);
});

it('keeps a prospect someone has worked on, and only unlinks it', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();
    $admin = User::factory()->create(['email' => 'hq@example.test']);

    $entry = WaitlistEntry::factory()->create();
    app(SyncWaitlistEntryToOperator::class)($entry, $this->operator);
    $link = OperatorLink::query()->sole();

    app(LogActivity::class)($this->operatorOwner, $this->operator, ['account_id' => $link->crm_account_id, 'person_id' => $link->crm_person_id, 'type' => 'CALL', 'subject' => 'Ringde om starten', 'done' => true]);
    CurrentCompany::forget();

    $released = app(DeleteWaitlistEntry::class)($admin, $entry);

    expect($released)->toBe(['erased_accounts' => 0, 'erased_people' => 0, 'kept' => 2])
        ->and(OperatorLink::query()->count())->toBe(0)
        ->and(CurrentCompany::run($this->operator, fn () => [Account::query()->count(), Person::query()->count()]))->toBe([1, 1]);
});

it('erases the prospect when waitlist:prune removes the entry', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();

    $entry = WaitlistEntry::factory()->create();
    app(SyncWaitlistEntryToOperator::class)($entry, $this->operator);
    $entry->forceFill(['created_at' => now()->subMonths(13)])->save();

    $this->artisan('waitlist:prune')->assertSuccessful();

    expect(WaitlistEntry::query()->count())->toBe(0)
        ->and(CurrentCompany::run($this->operator, fn () => [Account::query()->count(), Person::query()->count()]))->toBe([0, 0]);
});

it('never lets a failed sync break the company creation, and lists the failure in HQ', function () {
    // The operator company's owner is gone: the target's Actions have no one to act as.
    $this->operatorOwner->forceFill(['anonymised_at' => now()])->save();

    $company = app(CreateCompany::class)->create(User::factory()->create(), operatorCompanyInput());

    $link = OperatorLink::query()->sole();

    expect($company->exists)->toBeTrue()
        ->and($link->source_company_id)->toBe($company->id)
        ->and($link->status)->toBe(OperatorLink::FAILED)
        ->and($link->failed_at)->not->toBeNull()
        ->and($link->error)->toBe('RuntimeException')
        ->and(operatorCustomers($this->operator))->toHaveCount(0);

    $admin = User::factory()->create(['email' => 'hq@example.test']);
    $this->actingAs($admin);

    Livewire::test(Settings::class)->assertSee('Synk misslyckades')->assertSee('Företag '.$company->id);
});

it('backfills existing companies and queue entries idempotently, and --dry-run writes nothing', function () {
    operatorSettings([InstanceSettings::OPERATOR => null]);
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();

    $companies = Company::factory()->count(3)->create();
    Company::factory()->create(['archived_at' => now()]);
    WaitlistEntry::factory()->count(2)->create();
    WaitlistEntry::factory()->status(WaitlistStatus::Rejected)->create();

    operatorSettings([InstanceSettings::OPERATOR => $this->operator->id]);
    $before = AuditLog::query()->count();

    $this->artisan('hq:sync-operator-customers', ['--dry-run' => true])
        ->expectsOutputToContain('Kön: 2 anmälningar, 0 redan synkade, 2 att synka')
        ->expectsOutputToContain('Företag: 4 i installationen, 0 redan kunder, 3 blir kunder')
        ->doesntExpectOutputToContain($companies[0]->name)
        ->assertSuccessful();

    expect(OperatorLink::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe($before)
        ->and(operatorCustomers($this->operator))->toHaveCount(0);

    $this->artisan('hq:sync-operator-customers')->assertSuccessful();
    $this->artisan('hq:sync-operator-customers')->assertSuccessful();

    expect(operatorCustomers($this->operator))->toHaveCount(3)
        ->and(CurrentCompany::run($this->operator, fn () => Account::query()->whereNull('customer_id')->count()))->toBe(2)
        ->and(OperatorLink::query()->count())->toBe(5);
});

it('runs a failed sync again from HQ with a passkey step', function () {
    $this->operatorOwner->forceFill(['anonymised_at' => now()])->save();
    $company = Company::factory()->create();
    expect(OperatorLink::query()->sole()->status)->toBe(OperatorLink::FAILED);

    $this->operatorOwner->forceFill(['anonymised_at' => null])->save();
    $admin = User::factory()->create(['email' => 'hq@example.test']);
    $this->actingAs($admin);

    Livewire::test(Settings::class)->call('retryFailed', null)->assertHasErrors('passkey');
    expect(OperatorLink::query()->sole()->failed_at)->not->toBeNull();

    session()->put('hq.step_up', ['user_id' => $admin->id, 'at' => now()->getTimestamp()]);
    Livewire::test(Settings::class)->call('retryFailed', null)->assertHasNoErrors();

    $link = OperatorLink::query()->sole();
    expect($link->failed_at)->toBeNull()
        ->and($link->status)->toBe(OperatorLink::SYNCED)
        ->and($link->source_company_id)->toBe($company->id)
        ->and(operatorCustomers($this->operator))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'hq.operator_sync_retried')->sole()->payload)->toEqual(['count' => 1]);
});
