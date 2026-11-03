<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\AccountsPage;
use App\Modules\Crm\Livewire\ActivitiesPage;
use App\Modules\Crm\Livewire\CustomerFieldsForm;
use App\Modules\Crm\Livewire\PeoplePage;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * The CRM's screens (ADR-043): the list with filters in the query string,
 * the account card with its quick buttons and next step, Mina aktiviteter
 * with done in one tap, and Personer. Every save gives a toast.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
});

it('lists, searches, filters and sorts the accounts with the filters in the query string', function () {
    $seller = Bookkeeping::member($this->company, 'sales');
    Crm::prospect($this->company, $this->owner, 'Alfa Bygg AB', ['city' => 'Lund', 'tags' => 'bygg']);
    Crm::prospect($this->company, $this->owner, 'Beta Cafe AB', ['city' => 'Malmö', 'owner_id' => $seller->id]);
    $customer = Crm::accountFor(Crm::customer($this->company, $this->owner, 'Gamma Konsult AB', ['city' => 'Lund']));
    $person = Crm::person($this->company, $this->owner, 'Doris Dahl');
    app(LinkPerson::class)($this->owner, $this->company, $customer, $person, PersonRole::DecisionMaker);

    Livewire::test(AccountsPage::class)
        ->assertSeeInOrder(['Alfa Bygg AB', 'Beta Cafe AB', 'Gamma Konsult AB'])
        ->set('city', 'Lund')->assertSee('Alfa Bygg AB')->assertDontSee('Beta Cafe AB')
        ->set('tag', 'bygg')->assertSee('Alfa Bygg AB')->assertDontSee('Gamma Konsult AB')
        ->call('clearFilters')
        ->set('owner', (string) $seller->id)->assertSee('Beta Cafe AB')->assertDontSee('Alfa Bygg AB')
        ->set('owner', '')
        ->set('search', 'doris')->assertSee('Gamma Konsult AB')->assertDontSee('Alfa Bygg AB')
        ->set('search', '')
        ->set('sort', 'ort')->assertSeeInOrder(['Alfa Bygg AB', 'Gamma Konsult AB', 'Beta Cafe AB']);

    $this->get(route('invoicing.customers', ['status' => 'PROSPECT', 'ort' => 'Malmö']))->assertOk()
        ->assertSee('Beta Cafe AB')->assertDontSee('Alfa Bygg AB');
});

it('keeps the list at a fixed number of queries for 2 and 25 accounts', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(AccountsPage::class);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(1, 2) as $i) {
        $account = Crm::accountFor(Crm::customer($this->company, $this->owner, "Kund {$i}"));
        $account->forceFill(['owner_id' => $this->owner->id])->save();
        app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'TASK', 'subject' => "Ring {$i}"]);
    }
    // The first render also loads the company and the member's role; measure warm.
    $count();
    $few = $count();

    foreach (range(3, 25) as $i) {
        $account = Crm::accountFor(Crm::customer($this->company, $this->owner, "Kund {$i}"));
        $account->forceFill(['owner_id' => Bookkeeping::member($this->company, 'sales')->id])->save();
        app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'TASK', 'subject' => "Ring {$i}"]);
    }

    expect($count())->toBe($few);
});

it('adds a prospect from the list and opens its card with a toast', function () {
    Livewire::test(AccountsPage::class)
        ->call('create')
        ->set('form.name', 'Nytt Prospekt AB')
        ->set('form.city', 'Kiruna')
        ->call('save')
        ->assertRedirect(route('crm.accounts.show', Account::query()->where('name', 'Nytt Prospekt AB')->firstOrFail()));

    expect(Toast::pending()[0]['message'] ?? null)->toBe('Nytt Prospekt AB är tillagt som prospekt.');
});

it('logs a call from the quick button, completes the next step and asks for the one after', function () {
    $account = Crm::prospect($this->company, $this->owner, 'Kortet AB', ['phone' => '08-123 456 78']);

    $this->get(route('crm.accounts.show', $account))->assertOk()
        ->assertSee('Kortet AB')
        ->assertSee('href="tel:0812345678"', escape: false)
        ->assertSee('Inget planerat.');

    $card = Livewire::test(AccountCard::class, ['accountId' => $account->id])
        ->call('startActivity', 'CALL')
        ->assertSet('activity.done', true)
        ->set('activity.call_outcome', 'NO_ANSWER')
        ->call('saveActivity')
        ->assertDispatched('toast', message: 'Samtal är loggad.')
        ->call('startActivity', 'MEETING')
        ->set('activity.subject', 'Visning av lokalen')
        ->set('activity.due_at', '2026-06-18T10:00')
        ->call('saveActivity')
        ->assertDispatched('toast', message: 'Möte är planerad.')
        ->assertSee('Visning av lokalen');

    $meeting = Activity::query()->where('subject', 'Visning av lokalen')->firstOrFail();

    $card->call('startCompletion', $meeting->id)
        ->set('completion.note', 'Gick bra, vill ha offert.')
        ->call('complete')
        ->assertSet('panel', 'next')
        ->assertDispatched('toast', message: 'Klart. Vad är nästa steg?')
        ->set('activity.subject', 'Skicka offert')
        ->call('saveActivity')
        ->assertSet('panel', '');

    expect($meeting->fresh()->isOpen())->toBeFalse()
        ->and(Activity::query()->where('subject', 'Skicka offert')->whereNull('done_at')->exists())->toBeTrue()
        ->and(Activity::query()->where('type', 'CALL')->first()?->call_outcome?->value)->toBe('NO_ANSWER');

    // Skipping the next step is one tap.
    $task = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'TASK', 'subject' => 'Kolla mejl']);
    $card->call('startCompletion', $task->id)->call('complete')->call('skipNextStep')->assertSet('panel', '');
});

it('adds a person on the card with a role and legal basis, and changes the status with a reason', function () {
    $account = Crm::prospect($this->company, $this->owner);

    Livewire::test(AccountCard::class, ['accountId' => $account->id])
        ->call('startPerson')
        ->assertSet('personForm.legal_basis', 'LEGITIMATE_INTEREST')
        ->set('personForm.name', 'Erik Ek')
        ->set('personForm.mobile', '070-123 45 67')
        ->set('personForm.role', 'DECISION_MAKER')
        ->call('savePerson')
        ->assertDispatched('toast', message: 'Erik Ek är tillagd.')
        ->assertSee('Erik Ek')
        ->assertSee('href="tel:0701234567"', escape: false)
        ->call('startStatus')
        ->set('newStatus', 'LOST')
        ->call('saveStatus')
        ->assertHasErrors('reason')
        ->set('lostReason', 'Valde en annan leverantör')
        ->call('saveStatus')
        ->assertDispatched('toast', message: 'Statusen är nu förlorad.');

    expect($account->people()->first()?->link->role)->toBe(PersonRole::DecisionMaker);
});

it('turns a prospect into a customer from the card, and shows the key figures and ledger fields after', function () {
    $account = Crm::prospect($this->company, $this->owner, 'Blir Kund AB');

    Livewire::test(AccountCard::class, ['accountId' => $account->id])
        ->assertSee('Gör till kund')
        ->call('convert')
        ->assertDispatched('toast');

    $customer = Customer::query()->where('name', 'Blir Kund AB')->firstOrFail();

    $this->get(route('crm.accounts.show', $account))->assertOk()
        ->assertSee('Fakturerat 12 mån')
        ->assertSee(route('invoicing.quotes.create', ['kund' => $customer->id]), escape: false)
        ->assertSeeLivewire(CustomerFieldsForm::class);

    Livewire::test(CustomerFieldsForm::class, ['customerId' => $customer->id])
        ->set('form.payment_terms_days', '15')
        ->call('save')
        ->assertRedirect(route('crm.accounts.show', $account));

    expect($customer->fresh()->payment_terms_days)->toBe(15);

    // The quote editor starts with the customer from ?kund=.
    $this->get(route('invoicing.quotes.create', ['kund' => $customer->id]))->assertOk();
});

it('shows a seller the card without the ledger fields and without converting', function () {
    $seller = Bookkeeping::member($this->company, 'sales');
    $account = Crm::accountFor(Crm::customer($this->company, $this->owner, 'Säljkund AB'));
    $prospect = Crm::prospect($this->company, $this->owner);

    $this->actingAs($seller)->get(route('crm.accounts.show', $account))->assertOk()
        ->assertSee('Säljkund AB')
        ->assertDontSeeLivewire(CustomerFieldsForm::class);

    $this->actingAs($seller);
    Livewire::test(AccountCard::class, ['accountId' => $prospect->id])->assertDontSee('Gör till kund');
});

it('lets an auditor read the card and refuses every change', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $account = Crm::prospect($this->company, $this->owner);
    $this->actingAs($auditor);

    Livewire::test(AccountCard::class, ['accountId' => $account->id])
        ->assertDontSee('Anteckning</button>', escape: false)
        ->call('startActivity', 'NOTE')
        ->assertForbidden();
});

it('lists my activities overdue, today and coming, completes one in one tap and plans the next step', function () {
    $account = Crm::prospect($this->company, $this->owner, 'Aktivt AB');
    $log = app(LogActivity::class);
    $log($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'CALL', 'subject' => 'Försenat samtal', 'due_at' => '2026-06-12T09:00']);
    $today = $log($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'TASK', 'subject' => 'Dagens uppgift', 'due_at' => '2026-06-15T16:00']);
    $log($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'MEETING', 'subject' => 'Kommande möte', 'due_at' => '2026-06-20T10:00']);
    $other = Bookkeeping::member($this->company, 'sales');
    $log($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'TASK', 'subject' => 'Någon annans', 'assigned_to' => $other->id, 'due_at' => '2026-06-15T09:00']);

    Livewire::test(ActivitiesPage::class)
        ->assertSeeInOrder(['Försenade', 'Försenat samtal', 'I dag', 'Dagens uppgift', 'Kommande', 'Kommande möte'])
        ->assertDontSee('Någon annans')
        ->call('done', $today->id)
        ->assertDispatched('toast', message: 'Klart. Vad är nästa steg?')
        ->assertSee('Nästa steg för Aktivt AB')
        ->set('next.subject', 'Följ upp')
        ->call('saveNext')
        ->assertDispatched('toast', message: 'Nästa steg är planerat.')
        ->set('who', 'alla')
        ->assertSee('Någon annans');

    expect($today->fresh()->isOpen())->toBeFalse()
        ->and(Activity::query()->where('subject', 'Följ upp')->whereNull('done_at')->exists())->toBeTrue();

    $this->get(route('crm.activities'))->assertOk()->assertSee('Försenat samtal');
});

it('adds, changes and lists people, and shows who is to be pruned soon', function () {
    Livewire::test(PeoplePage::class)
        ->call('startPerson')
        ->set('personForm.name', 'Fia Fors')
        ->set('personForm.legal_basis', 'CONSENT')
        ->call('savePerson')
        ->assertHasErrors('consent_at')
        ->set('personForm.consent_at', '2026-06-01')
        ->call('savePerson')
        ->assertDispatched('toast', message: 'Fia Fors är tillagd.')
        ->assertSee('Fia Fors')
        ->set('show', 'gallras')
        ->assertDontSee('Fia Fors');

    $old = Crm::person($this->company, $this->owner, 'Gammal Kontakt');
    Person::query()->whereKey($old->id)->update(['last_activity_at' => '2024-07-01 10:00:00', 'updated_at' => '2024-07-01 10:00:00']);

    Livewire::test(PeoplePage::class, [])->set('show', 'gallras')->assertSee('Gammal Kontakt')->assertDontSee('Fia Fors');
    $this->get(route('crm.people'))->assertOk()->assertSee('Fia Fors');
});
