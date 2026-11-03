<?php

declare(strict_types=1);

use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Privacy\EraseRegisterEntry;
use App\Actions\Privacy\RestrictProcessing;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Livewire\Invoicing\SalesDocumentEditor;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Modules\Crm\Actions\ConvertToCustomer;
use App\Modules\Crm\Actions\EnsureCustomerAccounts;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SaveAccount;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Actions\SetAccountStatus;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\AccountsPage;
use App\Modules\Crm\Livewire\PeoplePage;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Suppression;
use App\Modules\ModuleManager;
use App\Support\Money\Money;
use App\Support\Navigation\Navigation;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * The review of CRM stage 1 (ADR-043, "Review round"): no personal data in
 * the audit log, anonymised and restricted customers, personal numbers,
 * "Kontakta inte", what a seller sees of a private person, the customer
 * form for "Lägg till kund", the navigation and the person extract with
 * the module off.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
});

it('never writes a name, e-mail, phone, organisation number or free text of the CRM to the audit log', function () {
    $account = app(SaveAccount::class)($this->owner, $this->company, ['name' => 'Hemligt Namn AB', 'org_number' => '556036-0793', 'email' => 'hemlig@exempel.test', 'phone' => '08-465 004 99', 'source' => 'Hemlig källa']);
    app(SaveAccount::class)($this->owner, $this->company, ['name' => 'Annat Hemligt AB', 'org_number' => '556036-0793', 'email' => 'annan@exempel.test', 'phone' => '08-465 004 98'], $account);
    app(SetAccountStatus::class)($this->owner, $this->company, $account->fresh(), 'LOST', 'Hemlig orsak om Kalle');
    $person = app(SavePerson::class)($this->owner, $this->company, ['name' => 'Kalle Hemlig', 'email' => 'kalle@exempel.test', 'mobile' => '070-174 06 50', 'legal_basis' => 'LEGITIMATE_INTEREST', 'account_id' => $account->id]);
    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Kalle Hemligsson', 'email' => 'kalle2@exempel.test', 'legal_basis' => 'AGREEMENT'], $person);
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'NOTE', 'subject' => 'Hemlig rubrik', 'body' => 'Hemlig text']);
    app(EraseCrmData::class)->person($this->owner, $this->company, $person->fresh());

    $payloads = AuditLog::query()->where(fn ($query) => $query->where('action', 'like', 'crm.%')->orWhere('action', 'like', 'privacy.%'))->pluck('payload')->toJson(JSON_UNESCAPED_UNICODE);

    foreach (['Hemligt', 'hemlig@', 'annan@', '465 004', '556036', 'Hemlig orsak', 'Kalle', 'kalle', '174 06', 'Hemlig rubrik', 'Hemlig text', 'Hemlig källa'] as $secret) {
        expect($payloads)->not->toContain($secret);
    }

    expect(AuditLog::query()->where('action', 'crm.status_changed')->latest('id')->first()?->payload)->toMatchArray(['reason_given' => true]);
});

it('clears what the CRM knows when a booked customer is anonymised, in the same erasure', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Bokförd Kund AB', ['email' => 'kund@exempel.test']);
    $invoice = Crm::invoice($this->owner, $this->company, $customer, '2026-06-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]);
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-06-10'), Money::ofOre(12500));
    $account = Crm::accountFor($customer);
    app(SaveAccount::class)($this->owner, $this->company, ['name' => 'Bokförd Kund AB', 'email' => 'kund@exempel.test', 'website' => 'kund.example', 'source' => 'Mässa', 'tags' => ['vip'], 'sni_code' => '62010'], $account);
    $person = Crm::person($this->company, $this->owner);
    app(LinkPerson::class)($this->owner, $this->company, $account, $person, PersonRole::DecisionMaker);
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'person_id' => $person->id, 'type' => 'NOTE', 'subject' => 'Om kunden', 'body' => 'Privat text']);

    expect(app(EraseRegisterEntry::class)->customer($this->owner, $this->company, $customer->fresh()))->toBe('anonymised');

    $account->refresh();
    $activity = Activity::query()->where('account_id', $account->id)->firstOrFail();
    expect($account->only(['website', 'source', 'sni_code', 'lost_reason', 'email']))->toBe(['website' => null, 'source' => null, 'sni_code' => null, 'lost_reason' => null, 'email' => null])
        ->and($account->tags)->toBe([])
        ->and($account->name)->toStartWith('Anonymiserad kund')
        ->and(AccountPerson::query()->where('account_id', $account->id)->exists())->toBeFalse()
        ->and($activity->only(['subject', 'body', 'person_id']))->toBe(['subject' => 'Anonymiserad aktivitet', 'body' => null, 'person_id' => null]);
});

it('skips anonymised and restricted customers when accounts are created for the register', function () {
    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, confirmed: true);
    $kept = Crm::customer($this->company, $this->owner, 'Vanlig AB');
    $restricted = Crm::customer($this->company, $this->owner, 'Begränsad AB');
    app(RestrictProcessing::class)->customer($this->owner, $this->company, $restricted, true);
    $anonymised = Crm::customer($this->company, $this->owner, 'Raderad AB');
    $invoice = Crm::invoice($this->owner, $this->company, $anonymised, '2026-06-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]);
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-06-10'), Money::ofOre(12500));
    app(EraseRegisterEntry::class)->customer($this->owner, $this->company, $anonymised->fresh());
    Account::query()->delete();

    expect(app(EnsureCustomerAccounts::class)($this->company))->toBe(1)
        ->and(Account::query()->pluck('customer_id')->all())->toBe([$kept->id]);
});

it('opens a new invoice or quote with ?kund= only for a customer the dropdown offers, and hides the links on the card', function () {
    $active = Crm::customer($this->company, $this->owner, 'Aktiv AB');
    $restricted = Crm::customer($this->company, $this->owner, 'Begränsad AB');
    app(RestrictProcessing::class)->customer($this->owner, $this->company, $restricted, true);
    $inactive = Crm::customer($this->company, $this->owner, 'Inaktiv AB', ['is_active' => false]);

    Livewire::withQueryParams(['kund' => $active->id])->test(InvoiceEditor::class)->assertSet('customerId', (string) $active->id);

    foreach ([$restricted, $inactive] as $customer) {
        Livewire::withQueryParams(['kund' => $customer->id])->test(InvoiceEditor::class)->assertSet('customerId', '');
        Livewire::withQueryParams(['kund' => $customer->id])->test(SalesDocumentEditor::class, ['kind' => 'QUOTE'])->assertSet('customerId', '');
        Livewire::test(AccountCard::class, ['accountId' => Crm::accountFor($customer)->id])->assertDontSee('Ny offert')->assertDontSee('Ny faktura');
    }

    Livewire::test(AccountCard::class, ['accountId' => Crm::accountFor($active)->id])->assertSee('Ny offert')->assertSee('Ny order')->assertSee('Ny faktura');
});

it('makes one customer of a double click on Gör till kund', function () {
    $account = Crm::prospect($this->company, $this->owner, 'Dubbelklick AB');
    $stale = Account::query()->findOrFail($account->id);

    app(ConvertToCustomer::class)($this->owner, $this->company, $account);

    expect(fn () => app(ConvertToCustomer::class)($this->owner, $this->company, $stale))->toThrow(ValidationException::class, 'redan en kund')
        ->and(Customer::query()->where('name', 'Dubbelklick AB')->count())->toBe(1);
});

it('keeps no personal number in the CRM, and does not erase it from the register', function () {
    expect(fn () => Crm::prospect($this->company, $this->owner, 'Enskild firma', ['org_number' => '19811218-9876']))->toThrow(ValidationException::class, 'personnummer');

    $customer = Crm::customer($this->company, $this->owner, 'Firma Andersson', ['org_number' => '811218-9876']);
    $account = Crm::accountFor($customer);
    expect($account->org_number)->toBeNull();

    app(SaveAccount::class)($this->owner, $this->company, ['name' => 'Firma Andersson', 'city' => 'Lund'], $account);
    expect($customer->fresh()->org_number)->toBe('811218-9876')->and($customer->fresh()->city)->toBe('Lund');
});

it('flags a person Kontakta inte: only notes, shown on card and list, and flagged again when re-added after erasure', function () {
    $account = Crm::prospect($this->company, $this->owner);
    $person = app(SavePerson::class)($this->owner, $this->company, ['name' => 'Nej Tack', 'email' => 'Nej.Tack@Exempel.test', 'mobile' => '070-174 06 60', 'legal_basis' => 'LEGITIMATE_INTEREST', 'account_id' => $account->id]);

    expect(fn () => app(SavePerson::class)($this->owner, $this->company, ['name' => 'Nej Tack', 'legal_basis' => 'LEGITIMATE_INTEREST', 'do_not_contact' => true], $person))->toThrow(ValidationException::class, 'invänt');

    $person = app(SavePerson::class)($this->owner, $this->company, ['name' => 'Nej Tack', 'email' => 'Nej.Tack@Exempel.test', 'mobile' => '070-174 06 60', 'legal_basis' => 'LEGITIMATE_INTEREST', 'do_not_contact' => true, 'do_not_contact_reason' => 'OBJECTION', 'informed_at' => '2026-06-01'], $person);

    expect($person->doNotContact())->toBeTrue()
        ->and($person->informed_at?->toDateString())->toBe('2026-06-01')
        ->and(Suppression::query()->count())->toBe(2)
        ->and(Suppression::query()->pluck('hash')->toJson())->not->toContain('exempel');

    $log = app(LogActivity::class);
    expect(fn () => $log($this->owner, $this->company, ['account_id' => $account->id, 'person_id' => $person->id, 'type' => 'CALL']))->toThrow(ValidationException::class, 'inte bli kontaktad');
    expect($log($this->owner, $this->company, ['account_id' => $account->id, 'person_id' => $person->id, 'type' => 'NOTE', 'body' => 'Ville inte bli kontaktad.'])->isOpen())->toBeFalse();

    Livewire::test(AccountCard::class, ['accountId' => $account->id])->assertSee('Kontakta inte')->assertDontSee('Logga samtal');
    Livewire::test(PeoplePage::class)->assertSee('Kontakta inte');

    app(EraseCrmData::class)->person($this->owner, $this->company, $person->fresh());
    $again = app(SavePerson::class)($this->owner, $this->company, ['name' => 'Ny Igen', 'email' => 'nej.tack@exempel.test', 'legal_basis' => 'LEGITIMATE_INTEREST']);
    expect($again->doNotContact())->toBeTrue()->and($again->do_not_contact_reason?->value)->toBe('OBJECTION');

    // Withdrawn: the details leave the list.
    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Ny Igen', 'email' => 'nej.tack@exempel.test', 'legal_basis' => 'LEGITIMATE_INTEREST', 'do_not_contact' => false], $again);
    expect(app(SavePerson::class)($this->owner, $this->company, ['name' => 'Tredje', 'email' => 'nej.tack@exempel.test', 'legal_basis' => 'LEGITIMATE_INTEREST'])->doNotContact())->toBeFalse();
});

it('counts other activities that name the person before erasure', function () {
    $account = Crm::prospect($this->company, $this->owner);
    $person = Crm::person($this->company, $this->owner, 'Greta Garbo');
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'NOTE', 'body' => 'Pratade med greta garbo i receptionen.']);
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'person_id' => $person->id, 'type' => 'NOTE', 'body' => 'Greta Garbo, kopplad.']);
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'NOTE', 'body' => '100% ingen träff']);

    expect(app(EraseCrmData::class)->mentions($person))->toBe(1);

    Livewire::test(PeoplePage::class)->call('startPerson', $person->id)->assertSee('1 aktivitet som inte är kopplad till personen nämner namnet');
});

it('hides a private person\'s debts, payment time, reminders and invoice links from a seller', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Privatperson Svensson', ['personal_number' => '811218-9876']);
    Crm::invoice($this->owner, $this->company, $customer, '2026-05-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500]], '2026-05-10');
    $account = Crm::accountFor($customer);
    expect($account->kind->value)->toBe('PERSON');

    $seller = Bookkeeping::member($this->company, 'sales');
    $this->actingAs($seller);
    Livewire::test(AccountCard::class, ['accountId' => $account->id])
        ->assertSee('Fakturerat 12 mån')
        ->assertDontSee('Förfallet')
        ->assertDontSee('Betaltid i snitt')
        ->assertDontSee(route('invoicing.invoices.index'), escape: false);

    $this->actingAs($this->owner);
    Livewire::test(AccountCard::class, ['accountId' => $account->id])->assertSee('Förfallet')->assertSee('Betaltid i snitt');
});

it('opens a customer form from Lägg till kund for those who may add customers, and a prospect form for a seller', function () {
    Livewire::withQueryParams(['ny' => 1])->test(AccountsPage::class)
        ->assertSet('asCustomer', true)
        ->assertSee('Ny kund')
        ->set('form.name', 'Direktkund AB')
        ->call('save');

    $customer = Customer::query()->where('name', 'Direktkund AB')->firstOrFail();
    expect(Crm::accountFor($customer)->customer_id)->toBe($customer->id)
        ->and(Account::query()->where('name', 'Direktkund AB')->count())->toBe(1);

    $this->actingAs(Bookkeeping::member($this->company, 'sales'));
    Livewire::withQueryParams(['ny' => 1])->test(AccountsPage::class)->assertSet('asCustomer', false)->assertSee('Nytt prospekt');
});

it('shows Kunder once: under CRM for those who see it, under Register for the others', function () {
    $pages = collect(app(Navigation::class)->pages($this->owner, $this->company))->where('route', 'invoicing.customers');
    expect($pages->pluck('section')->all())->toBe(['CRM']);

    $approver = Bookkeeping::member($this->company, 'approver');
    $pages = collect(app(Navigation::class)->pages($approver, $this->company))->where('route', 'invoicing.customers');
    expect($pages->pluck('section')->all())->toBe(['Register']);
});

it('gives a contact person\'s extract with the module off to those who manage the company', function () {
    $person = Crm::person($this->company, $this->owner, 'Kvar Efteråt');
    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, confirmed: true);

    $this->get(route('privacy.export.crmPerson', $person))->assertOk()->assertJsonPath('person.namn', 'Kvar Efteråt');
    $this->actingAs(Bookkeeping::member($this->company, 'bookkeeper'))->get(route('privacy.export.crmPerson', $person))->assertForbidden();
});
