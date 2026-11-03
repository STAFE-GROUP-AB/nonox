<?php

declare(strict_types=1);

use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Privacy\EraseRegisterEntry;
use App\Actions\Privacy\RestrictProcessing;
use App\Models\SalesDocument;
use App\Modules\Crm\Actions\CreateWinBackTasks;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LoseDeal;
use App\Modules\Crm\Actions\SaveDeal;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Actions\SetAccountStatus;
use App\Modules\Crm\Actions\SetWinBack;
use App\Modules\Crm\Actions\WinDeal;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Livewire\DealCard;
use App\Modules\Crm\Livewire\DealsBoard;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\SuppressionList;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;
use Tests\Support\Crm;

/*
 * The review round of CRM stage 2 (ADR-043): win-back never reaches someone
 * who must not be contacted, a reminder is not contact, a stale open deal
 * keeps no one from pruning, an anonymised customer's deals are closed,
 * values are capped, a private person's ledger links stay hidden from a
 * seller, and a contact person marked Kontakta inte is no dead end.
 */

beforeEach(function () {
    Connect::setUp();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Connect::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->account = Crm::prospect($this->company, $this->owner, 'Prospektet AB');
    $this->reason = LossReason::query()->value('id');
});

function flagPerson(object $test, Person $person, string $reason): Person
{
    return app(SavePerson::class)($test->owner, $test->company, ['name' => $person->name, 'email' => $person->email, 'legal_basis' => 'LEGITIMATE_INTEREST', 'do_not_contact' => true, 'do_not_contact_reason' => $reason], $person);
}

function contact(object $test, Account $account, string $name = 'Anna Andersson'): Person
{
    $person = Crm::person($test->company, $test->owner, $name, ['email' => strtolower(str_replace(' ', '.', $name)).'@example.test']);
    app(LinkPerson::class)($test->owner, $test->company, $account, $person, PersonRole::DecisionMaker);

    return $person;
}

it('sets no win-back date when the deal\'s contact objected, refuses one, and the command clears a date set before', function () {
    $person = contact($this, $this->account);
    $early = app(LoseDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $this->account, 'Före', extra: ['person_id' => $person->id]), ['loss_reason_id' => $this->reason, 'win_back_at' => '2026-07-01']);
    $deal = Crm::deal($this->company, $this->owner, $this->account, 'Efter', extra: ['person_id' => $person->id]);
    flagPerson($this, $person, 'OBJECTION');

    $lost = app(LoseDeal::class)($this->owner, $this->company, $deal, ['loss_reason_id' => $this->reason]);

    expect($lost->win_back_at)->toBeNull()
        ->and(fn () => app(SetWinBack::class)($this->owner, $this->company, $lost, '2026-09-01'))->toThrow(ValidationException::class, 'Personen har bett att inte bli kontaktad')
        ->and(fn () => app(LoseDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $this->account, 'Tredje', extra: ['person_id' => $person->id]), ['loss_reason_id' => $this->reason, 'win_back_at' => '2026-09-01']))->toThrow(ValidationException::class, 'Personen har bett att inte bli kontaktad');

    $this->travelTo(CarbonImmutable::parse('2026-07-02 06:00:00'));
    expect(app(CreateWinBackTasks::class)($this->company))->toBe(0)
        ->and($early->fresh()?->win_back_at)->toBeNull()
        ->and(Activity::query()->where('subject', 'like', 'Vinn tillbaka%')->count())->toBe(0);
});

it('gives a lost private person no reminder when a person at the account is restricted', function () {
    $private = Crm::prospect($this->company, $this->owner, 'Privat Person', ['kind' => 'PERSON']);
    flagPerson($this, contact($this, $private, 'Privat Person'), 'RESTRICTION');

    $lost = app(SetAccountStatus::class)($this->owner, $this->company, $private, 'LOST', 'Valde annan');

    expect($lost->win_back_at)->toBeNull()
        ->and(fn () => app(SetWinBack::class)($this->owner, $this->company, $lost, '2026-09-01'))->toThrow(ValidationException::class, 'Behandlingen av uppgifterna är begränsad');
});

it('skips and clears win-back for a restricted customer, a suppressed address and an account won back since', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Begränsad AB');
    $restricted = Crm::accountFor($customer);
    $deal = app(LoseDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $restricted, 'Begränsad'), ['loss_reason_id' => $this->reason, 'win_back_at' => '2026-07-01']);
    app(RestrictProcessing::class)->customer($this->owner, $this->company, $customer, true);

    $suppressed = Crm::prospect($this->company, $this->owner, 'Spärrad AB', ['email' => 'info@sparrad.test']);
    $objector = Crm::person($this->company, $this->owner, 'Spärrad', ['email' => 'info@sparrad.test']);
    flagPerson($this, $objector, 'OBJECTION');
    SuppressionList::remember($objector->fresh());
    $suppressedDeal = app(LoseDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $suppressed, 'Spärrad'), ['loss_reason_id' => $this->reason]);

    $wonBack = app(LoseDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $this->account, 'Förlorad'), ['loss_reason_id' => $this->reason, 'win_back_at' => '2026-07-01']);
    $this->travelTo(CarbonImmutable::parse('2026-06-20 10:00:00'));
    app(WinDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $this->account, 'Ny affär'));

    expect($suppressedDeal->win_back_at)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-07-01 06:00:00'));
    expect(app(CreateWinBackTasks::class)($this->company))->toBe(0)
        ->and($deal->fresh()?->win_back_at)->toBeNull()
        ->and($wonBack->fresh()?->win_back_at)->toBeNull()
        ->and(Activity::query()->where('subject', 'like', 'Vinn tillbaka%')->count())->toBe(0);
});

it('gives no reminder when the deal contact\'s own address is on the suppression list, even without the flag', function () {
    $contact = contact($this, $this->account, 'Delad Adress');
    $objector = Crm::person($this->company, $this->owner, 'Annan Person', ['email' => $contact->email]);
    flagPerson($this, $objector, 'OBJECTION');
    SuppressionList::remember($objector->fresh());

    expect($contact->fresh()?->doNotContact())->toBeFalse();

    $lost = app(LoseDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $this->account, 'Delad', extra: ['person_id' => $contact->id]), ['loss_reason_id' => $this->reason]);

    expect($lost->win_back_at)->toBeNull()
        ->and(fn () => app(SetWinBack::class)($this->owner, $this->company, $lost, '2026-09-01'))->toThrow(ValidationException::class, 'Personen har bett att inte bli kontaktad');
});

it('refuses a win-back date in the past or three years or more ahead', function () {
    $lost = app(LoseDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $this->account), ['loss_reason_id' => $this->reason]);

    expect(fn () => app(SetWinBack::class)($this->owner, $this->company, $lost, '2026-06-14'))->toThrow(ValidationException::class, 'kan inte ha passerat')
        ->and(fn () => app(SetWinBack::class)($this->owner, $this->company, $lost, '2029-06-15'))->toThrow(ValidationException::class, 'högst tre år')
        ->and(app(SetWinBack::class)($this->owner, $this->company, $lost, '2026-06-15')->win_back_at?->toDateString())->toBe('2026-06-15');
});

it('makes the reminder without touching the pruning clock', function () {
    app(LoseDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $this->account), ['loss_reason_id' => $this->reason, 'win_back_at' => '2026-07-01']);
    Account::query()->whereKey($this->account->id)->update(['last_activity_at' => '2026-06-15 10:00:00']);

    $this->travelTo(CarbonImmutable::parse('2026-07-01 06:00:00'));
    expect(app(CreateWinBackTasks::class)($this->company))->toBe(1)
        ->and($this->account->fresh()?->last_activity_at?->toDateString())->toBe('2026-06-15');
});

it('closes an anonymised customer\'s open deals and clears every win-back date and open step', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Privat Kund', ['personal_number' => '811218-9876']);
    $invoice = Crm::invoice($this->owner, $this->company, $customer, '2026-06-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]);
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-06-10'), Money::ofOre(12500));
    $account = Crm::accountFor($customer);
    $open = Crm::deal($this->company, $this->owner, $account, 'Öppen');
    $lost = app(LoseDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $account, 'Förlorad'), ['loss_reason_id' => $this->reason, 'win_back_at' => '2026-12-01']);
    app(SetAccountStatus::class)($this->owner, $this->company, $account, 'LOST', 'Valde annan');

    app(EraseRegisterEntry::class)->customer($this->owner, $this->company, $customer->fresh());

    expect($open->fresh()?->status->value)->toBe('LOST')
        ->and($open->fresh()?->loss_reason_id)->toBeNull()
        ->and($lost->fresh()?->win_back_at)->toBeNull()
        ->and($account->fresh()?->win_back_at)->toBeNull()
        ->and(Activity::query()->where('account_id', $account->id)->whereNull('done_at')->count())->toBe(0);
});

it('caps a deal\'s value', function () {
    expect(fn () => Crm::deal($this->company, $this->owner, $this->account, 'För stor', Deal::MAX_VALUE_ORE + 1))->toThrow(ValidationException::class, 'för stort')
        ->and(Crm::deal($this->company, $this->owner, $this->account, 'Störst', Deal::MAX_VALUE_ORE)->value_ore)->toBe(Deal::MAX_VALUE_ORE)
        ->and(fn () => app(WinDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $this->account), ['value_ore' => Deal::MAX_VALUE_ORE + 1]))->toThrow(ValidationException::class, 'för stort');
});

it('refuses deals for a customer with restricted processing, as the card refuses sales', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Begränsad AB');
    app(RestrictProcessing::class)->customer($this->owner, $this->company, $customer, true);

    expect(fn () => Crm::deal($this->company, $this->owner, Crm::accountFor($customer)))->toThrow(ValidationException::class, 'begränsad behandling');
});

it('plans a deal\'s steps without a contact person marked Kontakta inte, with a notice on the card', function () {
    $person = contact($this, $this->account);
    $deal = Crm::deal($this->company, $this->owner, $this->account, extra: ['person_id' => $person->id]);
    flagPerson($this, $person, 'OBJECTION');
    $step = Activity::query()->where('deal_id', $deal->id)->whereNull('done_at')->sole();

    Livewire::test(DealCard::class, ['dealId' => $deal->id])
        ->assertSee('är märkt Kontakta inte')
        ->call('startStep')->set('step.subject', 'Ring växeln')->call('saveStep')->assertHasNoErrors()
        ->call('startCompletion', $step->id)->call('complete')->assertHasNoErrors();

    expect(Activity::query()->where('deal_id', $deal->id)->whereNull('done_at')->sole()->person_id)->toBeNull()
        ->and(app(SaveDeal::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'title' => 'Ny', 'person_id' => $person->id, 'next_step' => ['type' => 'CALL']])->activities()->sole()->person_id)->toBeNull();
});

it('hides a private person\'s linked quote from a seller, on the card and in the API', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Privat Person', ['personal_number' => '811218-9876']);
    $account = Crm::accountFor($customer);
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $customer->id, 'document_date' => '2026-06-15', 'rows' => [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]]);
    $deal = app(WinDeal::class)($this->owner, $this->company, Crm::deal($this->company, $this->owner, $account), ['quote_id' => $quote->id]);
    $seller = Bookkeeping::member($this->company, 'sales');

    Livewire::test(DealCard::class, ['dealId' => $deal->id])->assertSee('Offert '.$quote->number);
    $this->actingAs($seller);
    Livewire::test(DealCard::class, ['dealId' => $deal->id])->assertDontSee('Kopplad till');

    $key = Connect::apiKey($seller, $this->company, ['crm:view']);
    $this->withToken($key)->getJson("/api/v1/crm/deals/{$deal->id}")->assertOk()->assertJsonPath('data.quote_id', null);
    $this->withToken(Connect::apiKey($this->owner, $this->company, ['crm:view', 'invoices:view']))->getJson("/api/v1/crm/deals/{$deal->id}")->assertJsonPath('data.quote_id', $quote->id);
});

it('moves a card only with the Flytta button, never on a change of the select', function () {
    Crm::deal($this->company, $this->owner, $this->account);

    Livewire::test(DealsBoard::class)
        ->assertDontSeeHtml('wire:change="move(')
        ->assertDontSeeHtml('role="tab"')
        ->assertSeeHtml('$wire.move(');
});

it('lets privacy:prune erase a prospect and contact whose open deal has stood still past the cutoff', function () {
    $stale = Crm::prospect($this->company, $this->owner, 'Stillastående AB');
    $person = contact($this, $stale, 'Gammal Kontakt');
    $deal = Crm::deal($this->company, $this->owner, $stale, extra: ['person_id' => $person->id]);
    $old = '2024-01-01 10:00:00';
    Account::query()->whereKey([$stale->id, $this->account->id])->update(['last_activity_at' => $old, 'updated_at' => $old]);
    Person::query()->whereKey($person->id)->update(['last_activity_at' => $old, 'updated_at' => $old]);
    Deal::query()->whereKey($deal->id)->update(['updated_at' => $old]);

    $this->artisan('privacy:prune')->assertSuccessful();

    expect(Account::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(Person::query()->whereKey($person->id)->exists())->toBeFalse();
});
