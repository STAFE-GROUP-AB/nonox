<?php

declare(strict_types=1);

use App\Domain\Overview\TodoItem;
use App\Domain\Overview\TodoProviders;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\CrmTodos;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * GDPR in the CRM (ADR-043): the register extract covers the CRM, a contact
 * person has an extract of their own, and privacy:prune erases idle people
 * and prospects after the company's setting, announced 30 days ahead in
 * "Att göra", unless they are linked to the ledger.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
});

it('includes the account and its activities in the customer\'s register extract', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB');
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => Crm::accountFor($customer)->id, 'type' => 'NOTE', 'subject' => 'Första mötet', 'body' => 'Bra stämning.']);

    $json = $this->get(route('privacy.export.customer', $customer))->assertOk()->json();

    expect($json['crm']['konto']['namn'])->toBe('Kontorshuset AB')
        ->and($json['crm']['aktiviteter'][0]['rubrik'])->toBe('Första mötet')
        ->and($json['crm']['aktiviteter'][0]['text'])->toBe('Bra stämning.');
});

it('gives a contact person\'s extract to those who manage the CRM, and 404 for another company\'s person', function () {
    $account = Crm::prospect($this->company, $this->owner, 'Prospektet AB');
    $person = Crm::person($this->company, $this->owner, 'Anna Andersson', ['email' => 'anna@example.test', 'legal_basis' => 'CONSENT', 'consent_at' => '2026-05-01']);
    app(LinkPerson::class)($this->owner, $this->company, $account, $person, PersonRole::DecisionMaker);
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'person_id' => $person->id, 'type' => 'CALL', 'subject' => 'Samtal', 'call_outcome' => 'REACHED', 'done' => true]);

    $json = $this->get(route('privacy.export.crmPerson', $person))->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="registerutdrag-kontaktperson-'.$person->id.'.json"')
        ->json();

    expect($json['person']['e_post'])->toBe('anna@example.test')
        ->and($json['person']['rattslig_grund'])->toBe('Samtycke')
        ->and($json['person']['samtycke_datum'])->toBe('2026-05-01')
        ->and($json['foretag'])->toBe([['namn' => 'Prospektet AB', 'roll' => 'Beslutsfattare']])
        ->and($json['aktiviteter'][0]['utfall'])->toBe('Fick tag på');

    $this->actingAs(Bookkeeping::member($this->company, 'auditor'))->get(route('privacy.export.crmPerson', $person))->assertForbidden();

    [$otherOwner, $otherCompany] = Bookkeeping::company();
    Crm::enable($otherCompany, $otherOwner);
    $this->actingAs($otherOwner)->get(route('privacy.export.crmPerson', $person))->assertNotFound();
});

it('erases idle people and prospects on privacy:prune, and keeps customers and invoice recipients at customers', function () {
    $idleProspect = Crm::prospect($this->company, $this->owner, 'Glömt prospekt AB');
    $freshProspect = Crm::prospect($this->company, $this->owner, 'Nytt prospekt AB');
    $customer = Crm::accountFor(Crm::customer($this->company, $this->owner, 'Kund AB'));
    $idlePerson = Crm::person($this->company, $this->owner, 'Glömd Person');
    $recipient = Crm::person($this->company, $this->owner, 'Fakturamottagaren');
    app(LinkPerson::class)($this->owner, $this->company, $customer, $recipient, PersonRole::InvoiceRecipient);
    $note = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $customer->id, 'person_id' => $idlePerson->id, 'type' => 'NOTE', 'subject' => 'Om Glömd Person']);

    $old = '2024-05-01 10:00:00';
    Account::query()->whereKey([$idleProspect->id, $customer->id])->update(['last_activity_at' => $old, 'updated_at' => $old]);
    Person::query()->whereKey([$idlePerson->id, $recipient->id])->update(['last_activity_at' => $old, 'updated_at' => $old]);

    // Announced 30 days ahead: the prospect and the person are 25 months idle.
    $todo = collect(app(CrmTodos::class)->todos($this->owner, $this->company, CarbonImmutable::today()))->first(fn (TodoItem $item): bool => $item->key === 'crm-prune');
    expect($todo?->title)->toBe('2 personer och prospekt i CRM raderas snart')
        ->and($todo?->url)->toBe(route('crm.people', ['visa' => 'gallras']));

    $this->artisan('privacy:prune')->expectsOutputToContain('crm_people: 1')->expectsOutputToContain('crm_prospects: 1')->assertSuccessful();

    expect(Account::query()->pluck('name')->sort()->values()->all())->toBe(['Kund AB', 'Nytt prospekt AB'])
        ->and(Person::query()->pluck('name')->all())->toBe(['Fakturamottagaren'])
        ->and($note->fresh()?->subject)->toBe('Anonymiserad aktivitet')
        ->and($freshProspect->fresh())->not->toBeNull();
});

it('leaves a company with the module off alone on privacy:prune', function () {
    $prospect = Crm::prospect($this->company, $this->owner);
    Account::query()->whereKey($prospect->id)->update(['last_activity_at' => '2020-01-01 10:00:00', 'updated_at' => '2020-01-01 10:00:00']);
    app(ModuleManager::class)->disable('crm', $this->company, $this->owner, confirmed: true);

    $this->artisan('privacy:prune')->assertSuccessful();

    expect(Account::query()->whereKey($prospect->id)->exists())->toBeTrue();
});

it('lists the user\'s own overdue activities in "Att göra", through the overview\'s personal providers', function () {
    $account = Crm::prospect($this->company, $this->owner);
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'CALL', 'subject' => 'Ring', 'due_at' => '2026-06-10T09:00']);
    $seller = Bookkeeping::member($this->company, 'sales');

    $mine = collect(app(CrmTodos::class)->todos($this->owner, $this->company, CarbonImmutable::today()));
    $theirs = collect(app(CrmTodos::class)->todos($seller, $this->company, CarbonImmutable::today()));

    expect($mine->firstWhere('key', 'crm-overdue')?->title)->toBe('1 aktivitet i CRM är försenad')
        ->and($theirs->firstWhere('key', 'crm-overdue'))->toBeNull()
        ->and(collect(app(TodoProviders::class)->personal($this->company))->contains(fn ($provider) => $provider instanceof CrmTodos))->toBeTrue()
        ->and(Activity::query()->count())->toBe(1);
});
