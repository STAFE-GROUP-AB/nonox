<?php

declare(strict_types=1);

use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Livewire\PersonCard;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * A contact person's page (ADR-043): header, facts, the path of their open
 * deal or their company's status, the activities in tabs, deals, what is
 * planned, the company's key figures and the GDPR box; Kontakta inte and a
 * restriction close the ways to contact; a fixed number of queries.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB', ['city' => 'Lund']);
    $this->account = Crm::accountFor($this->customer);
    $this->person = Crm::person($this->company, $this->owner, 'Anna Andersson', ['title' => 'Inköpschef', 'email' => 'anna@example.test', 'mobile' => '070-123 45 67', 'legal_basis' => 'CONSENT', 'consent_at' => '2026-05-01']);
    app(LinkPerson::class)($this->owner, $this->company, $this->account, $this->person, PersonRole::DecisionMaker);
});

function personPage(object $test): Testable
{
    return Livewire::test(PersonCard::class, ['personId' => $test->person->id]);
}

it('shows the header, the facts with the company and role, the legal basis and the status path without an open deal', function () {
    expect(route('crm.people.show', $this->person, false))->toBe("/kunder/personer/{$this->person->id}");

    $this->get(route('crm.people.show', $this->person))->assertOk()
        ->assertSee('Anna Andersson')
        ->assertSee('Inköpschef')
        ->assertSee('Kontorshuset AB')
        ->assertSee('Beslutsfattare')
        ->assertSee('Lund')
        ->assertSee('href="tel:0701234567"', escape: false)
        ->assertSee('href="mailto:anna@example.test"', escape: false)
        ->assertSee('Samtycke')
        ->assertSee('Kundens status')
        ->assertSeeInOrder(['Prospekt', 'Aktiv kund', 'Inaktiv', 'Förlorad'])
        ->assertSee('aria-current="step"', escape: false)
        ->assertSee('Kunden i siffror')
        ->assertSee(route('privacy.export.crmPerson', $this->person), escape: false);
});

it('shows the open deal\'s pipeline in the stepper, with a selector when there are several', function () {
    $first = Crm::deal($this->company, $this->owner, $this->account, 'Ramavtal', 1_000_000, ['person_id' => $this->person->id, 'stage_id' => Crm::stage(2)->id]);
    $second = Crm::deal($this->company, $this->owner, $this->account, 'Tillägg', 50_000, ['person_id' => $this->person->id]);

    personPage($this)
        ->assertSee('Ramavtal')
        ->assertSee('Visa affär')
        ->assertSeeInOrder(['Ny kontakt', 'Behov', 'Offert', 'Förhandling'])
        ->assertSeeHtml('aria-current="step"')
        ->set('dealId', (string) $second->id)
        ->assertSet('dealId', (string) $second->id)
        ->assertSee('Tillägg');

    expect($first->stage_id)->toBe(Crm::stage(2)->id);
});

it('lists the activities about the person in tabs with counts, and completes an open one with the next step', function () {
    $log = app(LogActivity::class);
    $log($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $this->person->id, 'type' => 'NOTE', 'subject' => 'Gillar korta möten']);
    $log($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $this->person->id, 'type' => 'CALL', 'subject' => 'Första samtalet', 'done' => true]);
    $task = $log($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $this->person->id, 'type' => 'TASK', 'subject' => 'Skicka prislista']);
    $log($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'NOTE', 'subject' => 'Om någon annan']);

    personPage($this)
        ->assertSee('Gillar korta möten')->assertSee('Första samtalet')->assertSee('Skicka prislista')->assertDontSee('Om någon annan')
        ->assertSeeHtml('role="tab"')->assertSeeHtml('role="tabpanel"')
        ->set('tab', 'samtal')->assertSee('Första samtalet')->assertDontSee('Gillar korta möten')
        ->set('tab', 'tidslinje')
        ->call('startCompletion', $task->id)
        ->call('complete')
        ->assertSet('panel', 'next')
        ->set('activity.subject', 'Följ upp prislistan')
        ->call('saveActivity');

    expect($task->fresh()?->isOpen())->toBeFalse()
        ->and(Activity::query()->where('subject', 'Följ upp prislistan')->sole()->person_id)->toBe($this->person->id);
});

it('logs a call and a note from the quick actions at the person\'s company', function () {
    personPage($this)
        ->call('startActivity', 'CALL')
        ->assertSet('activity.account_id', (string) $this->account->id)
        ->set('activity.subject', 'Snabbt samtal')
        ->call('saveActivity')
        ->assertDispatched('toast', message: 'Samtal är loggad.');

    expect(Activity::query()->where('subject', 'Snabbt samtal')->sole()->only(['account_id', 'person_id']))->toBe(['account_id' => $this->account->id, 'person_id' => $this->person->id]);
});

it('disables calling, mailing and planning for a person who objected, but keeps notes', function () {
    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Anna Andersson', 'email' => 'anna@example.test', 'mobile' => '070-123 45 67', 'legal_basis' => 'CONSENT', 'consent_at' => '2026-05-01', 'do_not_contact' => true, 'do_not_contact_reason' => 'OBJECTION'], $this->person);

    personPage($this)
        ->assertSeeHtml('data-testid="crm-do-not-contact"')
        ->assertDontSeeHtml('href="tel:0701234567"')
        ->assertDontSeeHtml('wire:click="startActivity(\'CALL\')"')
        ->assertDontSeeHtml('wire:click="startActivity(\'MEETING\')"')
        ->assertSeeHtml('wire:click="startActivity(\'NOTE\')"')
        ->assertSee('Personen har bett att inte bli kontaktad');
});

it('is read-only under a restriction, except completing what is already planned', function () {
    $task = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $this->person->id, 'type' => 'TASK', 'subject' => 'Planerad innan']);
    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Anna Andersson', 'legal_basis' => 'CONSENT', 'consent_at' => '2026-05-01', 'do_not_contact' => true, 'do_not_contact_reason' => 'RESTRICTION'], $this->person);

    personPage($this)
        ->assertSee('Behandlingen av personens uppgifter är begränsad')
        ->assertDontSeeHtml('wire:click="startActivity(\'NOTE\')"')
        ->assertDontSeeHtml('wire:click="startPerson(')
        ->assertDontSeeHtml('wire:click="editActivity(');

    expect($task->fresh()?->isOpen())->toBeFalse();
});

it('marks a person Kontakta inte from the GDPR box and lifts it again', function () {
    personPage($this)
        ->call('startDoNotContact')
        ->set('doNotContactReason', 'OBJECTION')
        ->call('saveDoNotContact')
        ->assertDispatched('toast');

    expect($this->person->fresh()?->do_not_contact_reason?->value)->toBe('OBJECTION');

    personPage($this)->call('saveDoNotContact', false);
    expect($this->person->fresh()?->doNotContact())->toBeFalse();
});

it('lets an auditor read the page but change nothing, and hides a private person\'s figures from a seller', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $this->actingAs($auditor);
    personPage($this)->assertOk()->assertDontSeeHtml('wire:click="startActivity(')->call('startActivity', 'NOTE')->assertForbidden();

    $this->actingAs($this->owner);
    $private = Crm::customer($this->company, $this->owner, 'Privat Person', ['personal_number' => '811218-9876']);
    Crm::invoice($this->owner, $this->company, $private, '2026-06-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]);
    $relative = Crm::person($this->company, $this->owner, 'Privat Person');
    app(LinkPerson::class)($this->owner, $this->company, Crm::accountFor($private), $relative, PersonRole::Other);

    $this->actingAs(Bookkeeping::member($this->company, 'sales'));
    Livewire::test(PersonCard::class, ['personId' => $relative->id])
        ->assertSee('bara för den som får se fakturorna')
        ->assertDontSee('Fakturerat 12 mån')
        ->assertDontSee('Betaltid i snitt');

    $this->actingAs(Bookkeeping::member($this->company, 'approver'));
    $this->get(route('crm.people.show', $this->person))->assertForbidden();
});

it('answers 404 for another company\'s person', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    Crm::enable($other, $otherOwner);
    $foreign = Crm::person($other, $otherOwner, 'Främling');
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->get(route('crm.people.show', $foreign))->assertNotFound();
    Livewire::test(PersonCard::class, ['personId' => $foreign->id])->assertNotFound();
});

it('links to the person page from the people list, the account card and the deal card', function () {
    $deal = Crm::deal($this->company, $this->owner, $this->account, extra: ['person_id' => $this->person->id]);
    $href = route('crm.people.show', $this->person);

    $this->get(route('crm.people'))->assertSee($href, escape: false);
    $this->get(route('crm.accounts.show', $this->account))->assertSee($href, escape: false);
    $this->get(route('crm.deals.show', $deal))->assertSee($href, escape: false);
});

it('renders with a fixed number of queries for a short and a long history', function () {
    $count = function (): int {
        Livewire::test(PersonCard::class, ['personId' => $this->person->id]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(PersonCard::class, ['personId' => $this->person->id]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $this->person->id, 'type' => 'NOTE', 'subject' => 'En']);
    Crm::deal($this->company, $this->owner, $this->account, 'Första', extra: ['person_id' => $this->person->id]);
    $few = $count();

    foreach (range(1, 25) as $i) {
        app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $this->person->id, 'type' => ['NOTE', 'CALL', 'MEETING', 'TASK'][$i % 4], 'subject' => "Aktivitet {$i}", 'done' => $i % 2 === 0]);
    }
    foreach (range(1, 6) as $i) {
        Crm::deal($this->company, $this->owner, $this->account, "Affär {$i}", extra: ['person_id' => $this->person->id]);
    }

    expect($count())->toBe($few);
});

it('keeps Person in sync after an edit from the page', function () {
    personPage($this)->call('startPerson', $this->person->id)->set('personForm.title', 'VD')->call('savePerson')->assertDispatched('toast');

    expect(Person::query()->find($this->person->id)?->title)->toBe('VD');
});
