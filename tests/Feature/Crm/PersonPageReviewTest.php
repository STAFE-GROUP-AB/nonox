<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\PersonCard;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * The review round of the person page and the new account card (ADR-043):
 * an activity stays at its own account, erasure from the person's own page
 * leads back to Personer, Kontakta inte has no call or mail links, a
 * limited seller sees no ledger date, the role is saved with a button and
 * does not reset the pruning clock, and an unknown tab is the timeline.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->alfa = Crm::prospect($this->company, $this->owner, 'Alfa AB');
    $this->beta = Crm::prospect($this->company, $this->owner, 'Beta AB');
    $this->person = Crm::person($this->company, $this->owner, 'Anna Andersson', ['email' => 'anna@example.test', 'mobile' => '070-123 45 67']);
    app(LinkPerson::class)($this->owner, $this->company, $this->alfa, $this->person, PersonRole::DecisionMaker);
    app(LinkPerson::class)($this->owner, $this->company, $this->beta, $this->person, PersonRole::User);
});

it('keeps an edited activity and the next step after one at their own account, also at a deal there', function () {
    $atBeta = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->beta->id, 'person_id' => $this->person->id, 'type' => 'TASK', 'subject' => 'Hos Beta']);
    $deal = Crm::deal($this->company, $this->owner, $this->beta, 'Beta-affär', extra: ['person_id' => $this->person->id]);
    $step = Activity::query()->where('deal_id', $deal->id)->sole();

    Livewire::test(PersonCard::class, ['personId' => $this->person->id])
        ->call('editActivity', $atBeta->id)
        ->set('activity.subject', 'Hos Beta, ändrad')
        ->call('saveActivity')
        ->assertHasNoErrors();

    expect($atBeta->fresh()?->account_id)->toBe($this->beta->id)
        ->and($atBeta->fresh()?->subject)->toBe('Hos Beta, ändrad');

    // The deal's last step: completed with the required next one, at Beta.
    Livewire::test(PersonCard::class, ['personId' => $this->person->id])
        ->call('startCompletion', $step->id)
        ->assertSet('dealStepRequired', true)
        ->set('activity.subject', 'Nästa hos Beta')
        ->call('complete')
        ->assertHasNoErrors();

    expect(Activity::query()->where('subject', 'Nästa hos Beta')->sole()->only(['account_id', 'deal_id']))->toBe(['account_id' => $this->beta->id, 'deal_id' => $deal->id]);

    // An ordinary completion: the next step goes to the same account and deal.
    $second = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->beta->id, 'person_id' => $this->person->id, 'deal_id' => $deal->id, 'type' => 'CALL', 'subject' => 'Ring Beta']);
    Livewire::test(PersonCard::class, ['personId' => $this->person->id])
        ->call('startCompletion', $second->id)
        ->call('complete')
        ->assertSet('panel', 'next')
        ->set('activity.subject', 'Efter samtalet')
        ->call('saveActivity')
        ->assertHasNoErrors();

    expect(Activity::query()->where('subject', 'Efter samtalet')->sole()->only(['account_id', 'deal_id']))->toBe(['account_id' => $this->beta->id, 'deal_id' => $deal->id]);
});

it('keeps an activity at an account the person has left when it is edited', function () {
    $atBeta = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->beta->id, 'person_id' => $this->person->id, 'type' => 'TASK', 'subject' => 'Gammalt']);
    app(LinkPerson::class)->unlink($this->owner, $this->company, $this->beta, $this->person);

    Livewire::test(PersonCard::class, ['personId' => $this->person->id])
        ->call('editActivity', $atBeta->id)
        ->assertSee('Beta AB')
        ->set('activity.subject', 'Gammalt, ändrat')
        ->call('saveActivity')
        ->assertHasNoErrors();

    expect($atBeta->fresh()?->account_id)->toBe($this->beta->id);
});

it('goes back to Personer with the message when the person is erased from their own page', function () {
    Livewire::test(PersonCard::class, ['personId' => $this->person->id])
        ->call('startPerson', $this->person->id)
        ->call('erasePerson')
        ->assertRedirect(route('crm.people'));

    expect(Person::query()->whereKey($this->person->id)->exists())->toBeFalse()
        ->and(Toast::pending()[0]['message'] ?? null)->toBe('Personen är raderad. Aktiviteterna om personen finns kvar utan namn och text.');
});

it('shows no call or mail links for a person marked Kontakta inte on the account card\'s Personer tab', function () {
    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Anna Andersson', 'email' => 'anna@example.test', 'mobile' => '070-123 45 67', 'legal_basis' => 'LEGITIMATE_INTEREST', 'do_not_contact' => true, 'do_not_contact_reason' => 'OBJECTION'], $this->person);

    Livewire::test(AccountCard::class, ['accountId' => $this->alfa->id])
        ->set('tab', 'personer')
        ->assertSee('anna@example.test')
        ->assertDontSeeHtml('href="mailto:anna@example.test"')
        ->assertDontSeeHtml('href="tel:0701234567"');
});

it('shows a limited seller no first-invoice date for a private person, on the card and the person page', function () {
    $private = Crm::customer($this->company, $this->owner, 'Privat Person', ['personal_number' => '811218-9876']);
    Crm::invoice($this->owner, $this->company, $private, '2026-03-02', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]);
    $account = Crm::accountFor($private);
    $relative = Crm::person($this->company, $this->owner, 'Privat Person');
    app(LinkPerson::class)($this->owner, $this->company, $account, $relative, PersonRole::Other);
    $this->artisan('crm:refresh-statuses')->assertSuccessful();

    Livewire::test(AccountCard::class, ['accountId' => $account->id])->assertSee('2 mar 2026');
    Livewire::test(PersonCard::class, ['personId' => $relative->id])->assertSee('2 mar 2026');

    $this->actingAs(Bookkeeping::member($this->company, 'sales'));
    Livewire::test(AccountCard::class, ['accountId' => $account->id])->assertDontSee('2 mar 2026');
    Livewire::test(PersonCard::class, ['personId' => $relative->id])->assertDontSee('2 mar 2026');
});

it('saves a role only with the button, and a changed role does not reset the pruning clock', function () {
    Person::query()->whereKey($this->person->id)->update(['last_activity_at' => '2025-01-01 10:00:00']);

    Livewire::test(AccountCard::class, ['accountId' => $this->alfa->id])
        ->set('tab', 'personer')
        ->assertDontSeeHtml('wire:change="changeRole(')
        ->assertSeeHtml('$wire.changeRole('.$this->person->id)
        ->call('changeRole', $this->person->id, 'AMBASSADOR');

    expect($this->alfa->people()->first()?->link->role)->toBe(PersonRole::Ambassador)
        ->and($this->person->fresh()?->last_activity_at?->toDateString())->toBe('2025-01-01');
});

it('turns an unknown tab into the timeline, on both pages', function () {
    Livewire::withQueryParams(['flik' => 'okand'])->test(PersonCard::class, ['personId' => $this->person->id])->assertSet('tab', 'tidslinje');
    Livewire::withQueryParams(['flik' => 'okand'])->test(AccountCard::class, ['accountId' => $this->alfa->id])->assertSet('tab', 'tidslinje');
    Livewire::test(PersonCard::class, ['personId' => $this->person->id])->set('tab', 'nonsens')->assertSet('tab', 'tidslinje');
});

it('describes a disabled quick action with a visible reason', function () {
    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Anna Andersson', 'email' => 'anna@example.test', 'mobile' => '070-123 45 67', 'legal_basis' => 'LEGITIMATE_INTEREST', 'do_not_contact' => true, 'do_not_contact_reason' => 'OBJECTION'], $this->person);

    Livewire::test(PersonCard::class, ['personId' => $this->person->id])
        ->assertSeeHtml('aria-disabled="true"')
        ->assertSeeHtml('aria-describedby="crm-actions-note"')
        ->assertSeeHtml('id="crm-actions-note"');
});

it('lifts a restriction only through Häv begränsningen, never from the ordinary form or a plain save (art. 18(3))', function () {
    $fields = ['name' => 'Anna Andersson', 'email' => 'anna@example.test', 'legal_basis' => 'LEGITIMATE_INTEREST'];
    app(SavePerson::class)($this->owner, $this->company, [...$fields, 'do_not_contact' => true, 'do_not_contact_reason' => 'RESTRICTION'], $this->person);

    expect(fn () => app(SavePerson::class)($this->owner, $this->company, [...$fields, 'do_not_contact' => false], $this->person->fresh()))
        ->toThrow(ValidationException::class, 'Häv begränsningen')
        ->and(fn () => app(SavePerson::class)($this->owner, $this->company, [...$fields, 'do_not_contact' => true, 'do_not_contact_reason' => 'OBJECTION'], $this->person->fresh()))
        ->toThrow(ValidationException::class, 'Häv begränsningen');

    $page = Livewire::test(PersonCard::class, ['personId' => $this->person->id])
        ->assertSee('Häv begränsningen')
        ->assertSee('GDPR artikel 18.3')
        ->call('startPerson', $this->person->id)
        ->assertSee('Begränsningen hävs bara med Häv begränsningen')
        ->assertDontSeeHtml('wire:model.live="personForm.do_not_contact"')
        ->set('personForm.title', 'VD')
        ->call('savePerson')
        ->assertHasNoErrors();

    expect($this->person->fresh()?->title)->toBe('VD')
        ->and($this->person->fresh()?->do_not_contact_reason?->value)->toBe('RESTRICTION');

    $page->set('personForm.lift_restriction', true)->call('startPerson', $this->person->id)->set('personForm.do_not_contact', false)->set('personForm.lift_restriction', true)->call('savePerson')->assertHasErrors('do_not_contact');
    expect($this->person->fresh()?->do_not_contact_reason?->value)->toBe('RESTRICTION');

    $page->call('closePanel')->call('saveDoNotContact', false, true);

    expect($this->person->fresh()?->doNotContact())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'crm.restriction_lifted')->where('subject_id', (string) $this->person->id)->value('payload'))->toBe(['person_id' => $this->person->id]);
});

it('tells a read-only user on the account card why the actions are disabled', function () {
    $this->actingAs(Bookkeeping::member($this->company, 'auditor'));

    Livewire::test(AccountCard::class, ['accountId' => $this->alfa->id])
        ->assertSeeHtml('id="crm-actions-note"')
        ->assertSee('Du kan inte ändra i CRM.');
});
