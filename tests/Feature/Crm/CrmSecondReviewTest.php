<?php

declare(strict_types=1);

use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Privacy\EraseRegisterEntry;
use App\Models\Customer;
use App\Modules\Crm\Actions\EnsureCustomerAccounts;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\AccountsPage;
use App\Modules\Crm\Livewire\PeoplePage;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Suppression;
use App\Modules\Crm\Support\CrmTodos;
use App\Modules\ModuleManager;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * The second review of CRM stage 1 (ADR-043): objection versus
 * restriction, the suppression list's key and review, a sole trader as a
 * new customer, an anonymised private person, and what a seller sees of a
 * private person.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->account = Crm::prospect($this->company, $this->owner);
});

function flagged(object $test, string $reason, string $email = 'flaggad@exempel.test', string $mobile = '070-174 06 70'): Person
{
    $person = app(SavePerson::class)($test->owner, $test->company, ['name' => 'Flaggad Person', 'email' => $email, 'mobile' => $mobile, 'legal_basis' => 'LEGITIMATE_INTEREST', 'account_id' => $test->account->id]);

    return app(SavePerson::class)($test->owner, $test->company, ['name' => 'Flaggad Person', 'email' => $email, 'mobile' => $mobile, 'legal_basis' => 'LEGITIMATE_INTEREST', 'do_not_contact' => true, 'do_not_contact_reason' => $reason], $person);
}

it('keeps nothing on the suppression list for a restriction, and nothing after its erasure', function () {
    $person = flagged($this, 'RESTRICTION');
    expect(Suppression::query()->count())->toBe(0);

    app(EraseCrmData::class)->person($this->owner, $this->company, $person);
    expect(Suppression::query()->count())->toBe(0)
        ->and(Crm::person($this->company, $this->owner, 'Igen', ['email' => 'flaggad@exempel.test'])->doNotContact())->toBeFalse();
});

it('allows one note documenting a restriction and nothing else', function () {
    $person = flagged($this, 'RESTRICTION');
    $log = app(LogActivity::class);

    expect(fn () => $log($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $person->id, 'type' => 'TASK']))->toThrow(ValidationException::class, 'inte bli kontaktad');

    $log($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $person->id, 'type' => 'NOTE', 'body' => 'Begärde begränsning.']);

    expect(fn () => $log($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $person->id, 'type' => 'NOTE', 'body' => 'En till.']))->toThrow(ValidationException::class, 'finns redan');
});

it('never lets an activity of a flagged person change into a call, meeting or e-mail', function () {
    $log = app(LogActivity::class);
    $person = Crm::person($this->company, $this->owner, 'Snart Flaggad', ['account_id' => $this->account->id]);
    $call = $log($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $person->id, 'type' => 'CALL', 'due_at' => '2026-06-20T10:00']);
    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Snart Flaggad', 'legal_basis' => 'LEGITIMATE_INTEREST', 'do_not_contact' => true, 'do_not_contact_reason' => 'OBJECTION'], $person);

    foreach (['MEETING', 'EMAIL', 'TASK'] as $type) {
        expect(fn () => $log($this->owner, $this->company, ['type' => $type], $call->fresh()))->toThrow(ValidationException::class, 'inte bli kontaktad');
    }

    expect($log($this->owner, $this->company, ['type' => 'NOTE', 'body' => 'Ändrat till anteckning.'], $call->fresh())->type->value)->toBe('NOTE');
});

it('still recognises an objector after the app key is rotated, and uses a dedicated key when one is set', function () {
    $person = flagged($this, 'OBJECTION', 'roterad@exempel.test');
    app(EraseCrmData::class)->person($this->owner, $this->company, $person);
    $old = config('app.key');

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$old]]);
    expect(Crm::person($this->company, $this->owner, 'Tillbaka', ['email' => 'roterad@exempel.test'])->doNotContact())->toBeTrue();

    config(['services.crm.suppression_key' => 'en-egen-nyckel', 'app.key' => $old, 'app.previous_keys' => []]);
    expect(Crm::person($this->company, $this->owner, 'Tillbaka igen', ['email' => 'roterad@exempel.test'])->doNotContact())->toBeTrue();
});

it('removes the old and new details when an objection is withdrawn, but keeps those another objector uses', function () {
    $first = flagged($this, 'OBJECTION', 'delad@exempel.test', '070-174 06 71');
    flagged($this, 'OBJECTION', 'delad@exempel.test', '070-174 06 72');

    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Flaggad Person', 'email' => 'ny@exempel.test', 'mobile' => '070-174 06 71', 'legal_basis' => 'LEGITIMATE_INTEREST', 'do_not_contact' => false], $first);

    // The shared e-mail and the second person's number stay; the first person's own number goes.
    expect(Suppression::query()->count())->toBe(2)
        ->and(Crm::person($this->company, $this->owner, 'Prov A', ['email' => 'delad@exempel.test'])->doNotContact())->toBeTrue()
        ->and(Crm::person($this->company, $this->owner, 'Prov B', ['mobile' => '070-174 06 71'])->doNotContact())->toBeFalse();
});

it('lists unreviewed suppression entries in Att göra, and keeps or removes them under Spärrlista', function () {
    flagged($this, 'OBJECTION');
    $todos = fn () => collect(app(CrmTodos::class)->todos($this->owner, $this->company, CarbonImmutable::today()))->firstWhere('key', 'crm-suppressions');
    expect($todos())->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2029-07-01 10:00:00'));
    expect($todos()?->title)->toBe('2 rader på spärrlistan i CRM ska granskas');

    $entries = Suppression::query()->orderBy('id')->get();
    Livewire::test(PeoplePage::class)
        ->set('show', 'sparrlista')
        ->assertSee('ska granskas')
        ->call('keepSuppression', $entries[0]->id)
        ->assertDispatched('toast')
        ->call('removeSuppression', $entries[1]->id);

    expect(Suppression::query()->count())->toBe(1)->and($todos())->toBeNull();
});

it('creates a sole trader as a new customer with the personal number in the register only', function () {
    Livewire::withQueryParams(['ny' => 1])->test(AccountsPage::class)
        ->set('form.name', 'Firma Enskild')
        ->set('form.org_number', '811218-9876')
        ->set('form.source', 'Tips')
        ->call('save')
        ->assertHasNoErrors();

    $customer = Customer::query()->where('name', 'Firma Enskild')->firstOrFail();
    $account = Crm::accountFor($customer);
    expect($customer->org_number)->toBe('811218-9876')
        ->and($account->org_number)->toBeNull()
        ->and($account->source)->toBe('Tips');
});

it('erases the people of an anonymised private person who are at no other account', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Privat Person', ['personal_number' => '811218-9876']);
    $invoice = Crm::invoice($this->owner, $this->company, $customer, '2026-06-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]);
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-06-10'), Money::ofOre(12500));
    $account = Crm::accountFor($customer);
    $only = Crm::person($this->company, $this->owner, 'Bara Här');
    $shared = Crm::person($this->company, $this->owner, 'Även Där');
    app(LinkPerson::class)($this->owner, $this->company, $account, $only, PersonRole::Other);
    app(LinkPerson::class)($this->owner, $this->company, $account, $shared, PersonRole::Other);
    app(LinkPerson::class)($this->owner, $this->company, $this->account, $shared, PersonRole::Other);

    app(EraseRegisterEntry::class)->customer($this->owner, $this->company, $customer->fresh());

    expect(Person::query()->pluck('name')->all())->toBe(['Även Där']);
});

it('reads a 12-digit legacy personal number in the register as one when accounts are created', function () {
    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, confirmed: true);
    $customer = Crm::customer($this->company, $this->owner, 'Gammal Firma');
    DB::table('customers')->where('id', $customer->id)->update(['org_number' => '19811218-9876']);

    app(EnsureCustomerAccounts::class)($this->company);

    expect(Account::query()->where('customer_id', $customer->id)->value('org_number'))->toBeNull();
});

it('shows a seller no key figures and no ledger entries for a private person, only the activities', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Privat Svensson', ['personal_number' => '811218-9876']);
    Crm::invoice($this->owner, $this->company, $customer, '2026-05-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500]]);
    $account = Crm::accountFor($customer);
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'NOTE', 'subject' => 'Synlig anteckning']);

    $this->actingAs(Bookkeeping::member($this->company, 'sales'));
    Livewire::test(AccountCard::class, ['accountId' => $account->id])
        ->assertSee('Synlig anteckning')
        ->assertSee('bara för den som får se fakturorna')
        ->assertDontSee('Fakturerat 12 mån')
        ->assertDontSee('Faktura 1');
    Livewire::test(AccountsPage::class)->assertSee('Privat Svensson')->assertDontSee('Fakturerat 12 mån');
});

it('counts the mentions once, when the person form opens', function () {
    $person = Crm::person($this->company, $this->owner, 'Räknad Person');
    $page = Livewire::test(PeoplePage::class)->call('startPerson', $person->id)->assertSet('mentionCount', 0);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $page->set('search', 'x');
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'ilike') && str_contains($sql, 'crm_activities'));
    DB::disableQueryLog();

    expect($queries)->toBeEmpty();
});
