<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Actions\Hq\ResolveOperatorReview;
use App\Actions\Hq\SyncCompanyToOperator;
use App\Actions\Hq\SyncWaitlistEntryToOperator;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Waitlist\ApproveWaitlistEntry;
use App\Actions\Waitlist\DeleteWaitlistEntry;
use App\Actions\Waitlist\JoinWaitlist;
use App\Domain\Company\LegalForm;
use App\Domain\Waitlist\WaitlistStatus;
use App\Livewire\Hq\Settings;
use App\Livewire\Hq\WaitlistEntryDetail;
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
use App\Modules\Crm\Models\Suppression;
use App\Support\Instance\InstanceSettings;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Toast;
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
        ->and($audit->payload)->toEqual(['source_company_id' => $company->id, 'target_company_id' => $this->operator->id, 'customer_id' => $customers[0]->id, 'outcome' => 'created', 'via' => 'operator_sync']);

    // The operator company's own entry says it was the sync, not its owner by hand.
    expect(AuditLog::query()->where('company_id', $this->operator->id)->where('action', 'customer.created')->sole()->payload['via'] ?? null)->toBe('operator_sync');
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

it('never links a customer by organisation number alone: it waits for HQ, and Koppla links it as it is', function () {
    $number = CompanyFactory::organisationNumber();
    $existing = app(SaveCustomer::class)($this->operatorOwner, $this->operator, ['name' => 'Gammal kund AB', 'org_number' => $number->formatted(), 'payment_terms_days' => 20, 'email' => 'faktura@example.test']);
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();

    $owner = User::factory()->create(['email' => 'kapare@example.test']);
    $company = Company::factory()->create(['user_id' => $owner->id, 'org_number' => $number->formatted(), 'name' => 'Ny Kund AB', 'legal_name' => 'Ny Kund AB', 'email' => 'kapare@example.test']);
    $company->forceFill(['name' => 'Ännu ett namn AB', 'legal_name' => 'Ännu ett namn AB'])->save();

    $link = OperatorLink::query()->sole();

    // Nothing linked, nothing written, nobody added as a contact.
    expect($link->status)->toBe(OperatorLink::NEEDS_REVIEW)
        ->and($link->customer_id)->toBeNull()
        ->and($link->review_customer_id)->toBe($existing->id)
        ->and(operatorCustomers($this->operator))->toHaveCount(1)
        ->and(operatorCustomers($this->operator)->sole()->name)->toBe('Gammal kund AB')
        ->and(operatorCustomers($this->operator)->sole()->email)->toBe('faktura@example.test')
        ->and(CurrentCompany::run($this->operator, fn () => Person::query()->count()))->toBe(0);

    $admin = User::factory()->create(['email' => 'hq@example.test']);
    $this->actingAs($admin);
    session()->put('hq.step_up', ['user_id' => $admin->id, 'at' => now()->getTimestamp()]);

    Livewire::test(Settings::class)
        ->assertSee('Koppla till befintlig kund?')
        ->assertSee('Gammal kund AB')
        ->assertSee('Ännu ett namn AB')
        ->call('resolve', null, $link->id, OperatorLink::DECISION_LINK)
        ->assertHasNoErrors()
        ->assertDontSee('Koppla till befintlig kund?');

    $link->refresh();
    $customer = operatorCustomers($this->operator)->sole();

    expect($link->status)->toBe(OperatorLink::SYNCED)
        ->and($link->customer_id)->toBe($existing->id)
        ->and($link->customer_created)->toBeFalse()
        // Linked as it is: the operator's data is not overwritten at linking.
        ->and($customer->name)->toBe('Gammal kund AB')
        ->and($customer->payment_terms_days)->toBe(20)
        ->and(AuditLog::query()->where('action', 'hq.operator_review_resolved')->sole()->payload)->toMatchArray(['decision' => 'LINK', 'review_customer_id' => $existing->id]);
});

it('creates a separate customer when HQ chooses Skapa ny kund, and never touches the existing one', function () {
    $number = CompanyFactory::organisationNumber();
    $existing = app(SaveCustomer::class)($this->operatorOwner, $this->operator, ['name' => 'Riktiga Bolaget AB', 'org_number' => $number->formatted(), 'payment_terms_days' => 20]);

    $company = Company::factory()->create(['org_number' => $number->formatted(), 'name' => 'Falska Bolaget AB', 'legal_name' => 'Falska Bolaget AB']);
    $link = OperatorLink::query()->sole();

    $admin = User::factory()->create(['email' => 'hq@example.test']);
    $this->actingAs($admin);
    session()->put('hq.step_up', ['user_id' => $admin->id, 'at' => now()->getTimestamp()]);

    Livewire::test(Settings::class)->call('resolve', null, $link->id, OperatorLink::DECISION_NEW)->assertHasNoErrors();

    $customers = operatorCustomers($this->operator);

    expect($customers)->toHaveCount(2)
        ->and($customers->firstWhere('id', $existing->id)->name)->toBe('Riktiga Bolaget AB')
        ->and($link->fresh()->customer_id)->not->toBe($existing->id)
        ->and($link->fresh()->customer_created)->toBeTrue();

    // A rename of the impostor reaches only its own customer.
    $company->forceFill(['name' => 'Bytt AB', 'legal_name' => 'Bytt AB'])->save();
    expect(operatorCustomers($this->operator)->firstWhere('id', $existing->id)->name)->toBe('Riktiga Bolaget AB');
});

it('never makes two customers of one company', function () {
    $company = Company::factory()->create();

    app(SyncCompanyToOperator::class)($company->fresh(), $this->operator);
    app(SyncCompanyToOperator::class)($company->fresh(), $this->operator);

    expect(operatorCustomers($this->operator))->toHaveCount(1)
        ->and(OperatorLink::query()->count())->toBe(1);
});

it('takes the current values as a new baseline after an APP_KEY rotation, instead of overwriting the operator\'s edits', function () {
    $company = Company::factory()->create(['name' => 'Före AB', 'legal_name' => 'Före AB']);
    $customer = operatorCustomers($this->operator)->sole();
    app(SaveCustomer::class)($this->operatorOwner, $this->operator, [...$customer->only(['name', 'org_number', 'vat_number', 'country']), 'email' => 'egen@example.test', 'payment_terms_days' => 10], $customer);

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $company->forceFill(['phone' => '08-123 45 67'])->save();

    expect(operatorCustomers($this->operator)->sole()->email)->toBe('egen@example.test')
        ->and(operatorCustomers($this->operator)->sole()->phone)->toBeNull();

    $company->forceFill(['name' => 'Efter AB', 'legal_name' => 'Efter AB'])->save();

    expect(operatorCustomers($this->operator)->sole()->name)->toBe('Efter AB')
        ->and(operatorCustomers($this->operator)->sole()->email)->toBe('egen@example.test');
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

it('adds the owner as a contact person when CRM is on: legitimate interest for a company, the agreement for a sole trader', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();

    $owner = User::factory()->create(['email' => 'ann@example.test', 'name' => 'Ann Ägare']);
    $company = Company::factory()->create(['user_id' => $owner->id]);

    CurrentCompany::run($this->operator, function () use ($company) {
        $customer = Customer::query()->sole();
        $account = Account::query()->where('customer_id', $customer->id)->sole();
        $person = Person::query()->sole();

        expect($person->email)->toBe('ann@example.test')
            ->and($person->legal_basis)->toBe(LegalBasis::LegitimateInterest)
            ->and(AccountPerson::query()->where('account_id', $account->id)->where('person_id', $person->id)->exists())->toBeTrue()
            ->and(OperatorLink::query()->where('source_company_id', $company->id)->sole()->crm_person_id)->toBe($person->id);
    });

    // A second sync adds nobody.
    app(SyncCompanyToOperator::class)($company->fresh(), $this->operator);
    expect(CurrentCompany::run($this->operator, fn () => Person::query()->count()))->toBe(1);

    $soleTrader = User::factory()->create(['email' => 'eva@example.test']);
    Company::factory()->create(['user_id' => $soleTrader->id, 'legal_form' => LegalForm::Ef, 'org_number' => '811228-987'.Luhn::checkDigit('811228987'), 'vat_number' => null]);

    expect(CurrentCompany::run($this->operator, fn () => Person::query()->where('email', 'eva@example.test')->sole()->legal_basis))->toBe(LegalBasis::Agreement);
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

it('skips the queue and says so in HQ while CRM is off in the operator company', function () {
    $entry = WaitlistEntry::factory()->create();

    expect(app(SyncWaitlistEntryToOperator::class)($entry, $this->operator))->toBe(SyncWaitlistEntryToOperator::CRM_OFF)
        ->and(OperatorLink::query()->count())->toBe(0);

    $admin = User::factory()->create(['email' => 'hq@example.test']);
    $this->actingAs($admin);

    Livewire::test(Settings::class)->assertSee('CRM är inte påslaget i driftbolaget');
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
            ->and($person->legal_basis)->toBe(LegalBasis::LegitimateInterest)
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

it('erases the prospect and the person when the queue entry is deleted on request, and keeps them from coming back', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();
    $admin = User::factory()->create(['email' => 'hq@example.test']);

    $entry = WaitlistEntry::factory()->create(['email' => 'borta@example.test', 'phone' => '070-111 22 33']);
    app(SyncWaitlistEntryToOperator::class)($entry, $this->operator);

    $released = app(DeleteWaitlistEntry::class)($admin, $entry);

    expect($released)->toBe(['erased_accounts' => 1, 'erased_people' => 1, 'kept' => 0])
        ->and(OperatorLink::query()->count())->toBe(0)
        ->and(CurrentCompany::run($this->operator, fn () => [Account::query()->count(), Person::query()->count()]))->toBe([0, 0])
        ->and(AuditLog::query()->where('action', 'hq.operator_prospect_released')->sole()->payload['by'])->toBe('request')
        ->and(CurrentCompany::run($this->operator, fn () => Suppression::query()->where('reason', 'ERASURE')->count()))->toBe(2);

    // The same address in the queue again: never added back by the sync.
    $again = WaitlistEntry::factory()->create(['email' => 'borta@example.test']);
    expect(app(SyncWaitlistEntryToOperator::class)($again, $this->operator))->toBe(SyncWaitlistEntryToOperator::SUPPRESSED)
        ->and(CurrentCompany::run($this->operator, fn () => Person::query()->count()))->toBe(0);
});

it('never links a queue applicant to an existing account or person without HQ', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    $number = CompanyFactory::organisationNumber();
    $existing = Crm::prospect($this->operator, $this->operatorOwner, 'Redan känt AB', ['org_number' => $number->formatted()]);
    CurrentCompany::forget();
    $admin = User::factory()->create(['email' => 'hq@example.test']);

    $entry = WaitlistEntry::factory()->create(['org_number' => $number->formatted(), 'company_name' => 'Påstått Bolag AB']);

    expect(app(SyncWaitlistEntryToOperator::class)($entry, $this->operator))->toBe(SyncWaitlistEntryToOperator::NEEDS_REVIEW);

    $link = OperatorLink::query()->sole();
    expect($link->status)->toBe(OperatorLink::NEEDS_REVIEW)
        ->and($link->review_account_id)->toBe($existing->id)
        ->and(CurrentCompany::run($this->operator, fn () => [Account::query()->count(), Person::query()->count(), AccountPerson::query()->count()]))->toBe([1, 0, 0]);

    // Syncing again changes nothing while HQ has not decided.
    expect(app(SyncWaitlistEntryToOperator::class)($entry, $this->operator))->toBe(SyncWaitlistEntryToOperator::NEEDS_REVIEW);

    app(ResolveOperatorReview::class)($admin, $link, OperatorLink::DECISION_LINK);

    $link->refresh();
    expect($link->status)->toBe(OperatorLink::SYNCED)
        ->and($link->crm_account_id)->toBe($existing->id)
        ->and($link->account_created)->toBeFalse()
        ->and($link->person_created)->toBeTrue();

    // Deleting the entry erases the person the sync made, never the account it did not.
    $released = app(DeleteWaitlistEntry::class)($admin, $entry);

    expect($released)->toBe(['erased_accounts' => 0, 'erased_people' => 1, 'kept' => 0])
        ->and(CurrentCompany::run($this->operator, fn () => [Account::query()->pluck('id')->all(), Person::query()->count()]))->toBe([[$existing->id], 0]);
});

it('dates the information for a consent given before the privacy policy mentioned the CRM', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();

    $entry = WaitlistEntry::factory()->create(['consent_at' => CarbonImmutable::parse('2026-11-01 12:00:00')]);
    app(SyncWaitlistEntryToOperator::class)($entry, $this->operator);

    CurrentCompany::run($this->operator, function () {
        $person = Person::query()->sole();

        expect($person->consent_at->toDateString())->toBe('2026-11-01')
            ->and($person->informed_at->toDateString())->toBe(SyncWaitlistEntryToOperator::INFORMED_FROM)
            ->and($person->legal_basis_note)->toContain('Informerad om CRM genom integritetspolicyn');
    });
});

it('on request erases a worked-on person anyway, and keeps only the company\'s account without them', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();
    $admin = User::factory()->create(['email' => 'hq@example.test']);

    $entry = WaitlistEntry::factory()->create(['org_number' => CompanyFactory::organisationNumber()->formatted()]);
    app(SyncWaitlistEntryToOperator::class)($entry, $this->operator);
    $link = OperatorLink::query()->sole();

    app(LogActivity::class)($this->operatorOwner, $this->operator, ['account_id' => $link->crm_account_id, 'person_id' => $link->crm_person_id, 'type' => 'CALL', 'subject' => 'Ringde om starten', 'done' => true]);
    CurrentCompany::forget();

    $this->actingAs($admin);
    session()->put('hq.step_up', ['user_id' => $admin->id, 'at' => now()->getTimestamp()]);
    Livewire::test(WaitlistEntryDetail::class, ['entryId' => $entry->id])->call('delete', null);

    expect(json_encode(session()->get(Toast::SESSION_KEY), JSON_UNESCAPED_UNICODE))->toContain('Företagets konto i CRM har aktiviteter eller affärer och ligger kvar utan personen.')
        ->and(OperatorLink::query()->count())->toBe(0)
        ->and(CurrentCompany::run($this->operator, fn () => [Account::query()->pluck('id')->all(), Person::query()->count()]))->toBe([[$link->crm_account_id], 0]);
});

it('on request erases a sole trader\'s account too, even when worked on', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();
    $admin = User::factory()->create(['email' => 'hq@example.test']);

    $entry = WaitlistEntry::factory()->create(['org_number' => '811228-987'.Luhn::checkDigit('811228987')]);
    app(SyncWaitlistEntryToOperator::class)($entry, $this->operator);
    $link = OperatorLink::query()->sole();
    app(LogActivity::class)($this->operatorOwner, $this->operator, ['account_id' => $link->crm_account_id, 'type' => 'NOTE', 'subject' => 'Anteckning']);
    CurrentCompany::forget();

    $released = app(DeleteWaitlistEntry::class)($admin, $entry);

    expect($released)->toBe(['erased_accounts' => 1, 'erased_people' => 1, 'kept' => 0])
        ->and(CurrentCompany::run($this->operator, fn () => [Account::query()->count(), Person::query()->count()]))->toBe([0, 0]);
});

it('keeps a worked-on prospect at pruning, no longer on the queue consent', function () {
    Crm::enable($this->operator, $this->operatorOwner);
    CurrentCompany::forget();

    $entry = WaitlistEntry::factory()->create();
    app(SyncWaitlistEntryToOperator::class)($entry, $this->operator);
    $link = OperatorLink::query()->sole();

    app(LogActivity::class)($this->operatorOwner, $this->operator, ['account_id' => $link->crm_account_id, 'person_id' => $link->crm_person_id, 'type' => 'CALL', 'subject' => 'Ringde om starten', 'done' => true]);
    CurrentCompany::forget();
    $clock = CurrentCompany::run($this->operator, fn () => Person::query()->sole()->last_activity_at);
    $entry->forceFill(['created_at' => now()->subMonths(13)])->save();
    $this->travel(1)->hours();

    $this->artisan('waitlist:prune')->assertSuccessful();

    CurrentCompany::run($this->operator, function () use ($clock) {
        $person = Person::query()->sole();

        expect($person->legal_basis)->toBe(LegalBasis::LegitimateInterest)
            ->and($person->legal_basis_note)->toBe('Kontakt i kön; behålls efter kontakt med personen.')
            ->and($person->consent_at)->toBeNull()
            ->and($person->last_activity_at?->equalTo($clock))->toBeTrue()
            ->and(Account::query()->count())->toBe(1);
    });

    expect(WaitlistEntry::query()->count())->toBe(0)->and(OperatorLink::query()->count())->toBe(0);
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
        ->expectsOutputToContain('Företag: 4 i installationen, 0 redan kunder, 3 att synka')
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

it('counts only the current operator company\'s links in HQ', function () {
    Company::factory()->create();
    $previous = Company::factory()->create(['is_test_company' => true]);
    OperatorLink::query()->create(['target_company_id' => $previous->id, 'source_company_id' => $this->operator->id, 'status' => OperatorLink::FAILED, 'failed_at' => now(), 'error' => 'RuntimeException']);

    $this->actingAs(User::factory()->create(['email' => 'hq@example.test']));

    Livewire::test(Settings::class)
        ->assertViewHas('customerLinks', 1)
        ->assertViewHas('failureCount', 0);
});
