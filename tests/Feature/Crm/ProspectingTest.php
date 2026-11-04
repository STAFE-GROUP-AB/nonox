<?php

declare(strict_types=1);

use App\Models\RegistryChange;
use App\Models\RegistryCompany;
use App\Models\RegistryImport;
use App\Modules\Crm\Actions\AddProspects;
use App\Modules\Crm\Actions\CreateCallList;
use App\Modules\Crm\Actions\CreateRegistryNotices;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Actions\WorkCallList;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Domain\LookAlike;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Livewire\CallListPage;
use App\Modules\Crm\Livewire\CallListsPage;
use App\Modules\Crm\Livewire\CrmSettingsForm;
use App\Modules\Crm\Livewire\ProspectingPage;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CallList;
use App\Modules\Crm\Models\CallListItem;
use App\Modules\Crm\Models\RegistryNotice;
use App\Modules\Crm\Support\SuppressionList;
use App\Modules\ModuleManager;
use App\Support\Luhn;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * CRM stage 4 (ADR-047): prospect search and look-alike search in the
 * instance's company register, adding prospects without duplicates, call
 * lists worked one company at a time, and monitoring of the accounts.
 * Synthetic register rows only (559999 6xxx and made-up names).
 */

/** A synthetic organisation number 559999 6NNC with a Luhn check digit. */
function prospectOrg(int $serial): string
{
    $nine = '5599996'.str_pad((string) $serial, 2, '0', STR_PAD_LEFT);

    return $nine.Luhn::checkDigit($nine);
}

/** @param  array<string, mixed>  $extra */
function registryRow(int $serial, string $name, array $extra = []): RegistryCompany
{
    return RegistryCompany::create([
        'org_number' => prospectOrg($serial),
        'name' => $name,
        'legal_form' => '49',
        'scb_status' => 1,
        'sni_code' => '62100',
        'sni_division' => '62',
        'sni_codes' => ['62100'],
        'address_line1' => "Exempelgatan {$serial}",
        'postal_code' => '11122',
        'city' => 'STOCKHOLM',
        'registered_on' => '2020-01-01',
        'sources' => ['scb-bulk'],
        'fetched_at' => now(),
        ...$extra,
    ]);
}

beforeEach(function () {
    DB::table('company_registry_changes')->delete();
    DB::table('company_registry')->delete();
    DB::table('company_registry_imports')->delete();
    RegistryImport::create(['source' => 'scb-bulk', 'status' => RegistryImport::DONE, 'started_at' => now(), 'finished_at' => now()]);

    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Crm::enable($this->company, $this->owner);
    $this->actingAs($this->owner);
});

it('answers 404 while CRM is off and shows the pages while it is on', function () {
    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, confirmed: true);
    $this->get(route('crm.prospecting'))->assertNotFound();
    $this->get(route('crm.call-lists'))->assertNotFound();

    app(ModuleManager::class)->enable(CrmModule::KEY, $this->company, $this->owner);
    $this->get('/kunder/prospektera')->assertOk()->assertSee('Prospektera')->assertSee('Sök eller välj ett filter');
    $this->get('/kunder/ringlistor')->assertOk()->assertSee('Inga ringlistor ännu');
});

it('says when the register is not loaded', function () {
    DB::table('company_registry_imports')->delete();

    Livewire::test(ProspectingPage::class)->assertSee('Företagsregistret är inte inläst')->assertSee('registry:import scb-bulk');
});

it('filters on industry, town, form and registration date, in business and without opt-outs by default', function () {
    registryRow(1, 'Kodverket Exempel AB');
    registryRow(2, 'Molnet Exempel AB', ['advertising_block' => true]);
    registryRow(3, 'Vilande Exempel AB', ['scb_status' => 9]);
    registryRow(4, 'Konkursbolaget Exempel AB', ['proceedings' => 'KK']);
    registryRow(5, 'Måleri Exempel HB', ['legal_form' => '31', 'sni_code' => '43341', 'sni_division' => '43', 'city' => 'GÖTEBORG']);
    registryRow(6, 'Nystartade Exempel AB', ['registered_on' => '2026-06-01']);

    $page = Livewire::test(ProspectingPage::class)->set('division', '62');
    $page->assertSee('Kodverket Exempel AB')->assertSee('Nystartade Exempel AB')
        ->assertDontSee('Molnet Exempel AB')->assertDontSee('Vilande Exempel AB')->assertDontSee('Konkursbolaget Exempel AB')->assertDontSee('Måleri Exempel HB')
        ->assertSee('62100 Dataprogrammering')
        ->assertSee('Källa: Bolagsverket och SCB, värdefulla datamängder, bearbetat av NONOX');

    $page->set('blocked', true)->assertSee('Molnet Exempel AB')->assertSee('Reklamspärr');
    $page->set('all', true)->assertSee('Vilande Exempel AB')->assertSee('Konkurs');
    $page->set('from', '2026-01-01')->assertSee('Nystartade Exempel AB')->assertDontSee('Kodverket Exempel AB');

    Livewire::test(ProspectingPage::class)->set('town', 'göteborg')->assertSee('Måleri Exempel HB')->assertDontSee('Kodverket Exempel AB');
    Livewire::test(ProspectingPage::class)->set('form', '31')->assertSee('Måleri Exempel HB')->assertDontSee('Kodverket Exempel AB');
    Livewire::withQueryParams(['huvudgrupp' => '43', 'sni' => '43341'])->test(ProspectingPage::class)->assertSee('Måleri Exempel HB');
});

it('adds one, the ticked or all hits as prospects without duplicates, and hides those already in the CRM', function () {
    registryRow(1, 'Kodverket Exempel AB');
    registryRow(2, 'Molntjänst Exempel AB');
    registryRow(3, 'Dataföretaget Exempel AB');
    Crm::prospect($this->company, $this->owner, 'Redan här AB', ['org_number' => prospectOrg(3)]);

    $page = Livewire::test(ProspectingPage::class)->set('division', '62')
        ->assertSee('Kodverket Exempel AB')->assertDontSee('Dataföretaget Exempel AB');

    $page->call('addOne', prospectOrg(1))->assertDispatched('toast');
    $account = Account::query()->where('name', 'Kodverket Exempel AB')->firstOrFail();

    expect($account->org_number)->toBe(substr(prospectOrg(1), 0, 6).'-'.substr(prospectOrg(1), 6))
        ->and($account->source)->toBe('Företagsregistret')
        ->and($account->sni_code)->toBe('62100')
        ->and($account->city)->toBe('Stockholm')
        ->and($account->postal_code)->toBe('111 22')
        ->and($account->owner_id)->toBe($this->owner->id);

    $page->assertDontSee('Kodverket Exempel AB');

    // Ticked again from an old page: counted as existing, not added twice.
    $result = app(AddProspects::class)($this->owner, $this->company, [prospectOrg(1), prospectOrg(2), prospectOrg(3)]);
    expect($result['added'])->toHaveCount(1)
        ->and($result['existing'])->toHaveCount(2)
        ->and(Account::query()->count())->toBe(3);

    $audit = DB::table('audit_logs')->where('action', 'crm.prospects_added')->latest('id')->first();
    expect(json_decode((string) $audit?->payload, true))->toBe(['added' => 1, 'existing' => 2, 'suppressed' => 0]);
});

it('adds all hits as a call list and opens it', function () {
    registryRow(1, 'Kodverket Exempel AB');
    registryRow(2, 'Molntjänst Exempel AB');
    $seller = Bookkeeping::member($this->company, 'sales');

    Livewire::test(ProspectingPage::class)->set('division', '62')
        ->set('asCallList', true)->set('listName', 'Stockholm IT')->set('listAssignee', (string) $seller->id)
        ->call('addAll')
        ->assertRedirect(route('crm.call-lists.show', CallList::query()->firstOrFail()));

    $list = CallList::query()->sole();
    expect($list->name)->toBe('Stockholm IT')
        ->and($list->assigned_to)->toBe($seller->id)
        ->and($list->criteria['source'] ?? null)->toBe('register')
        ->and(CallListItem::query()->orderBy('position')->pluck('account_id')->all())->toBe(Account::query()->orderBy('name')->pluck('id')->all())
        ->and(Toast::pending()[0]['message'] ?? '')->toContain('2 nya prospekt');
});

it('does not add a company whose register phone or e-mail someone has objected to', function () {
    registryRow(1, 'Kodverket Exempel AB', ['phone' => '08-465 004 01', 'email' => 'info@kodverket.test']);
    CurrentCompany::run($this->company, fn () => SuppressionList::suppress('OBJECTION', null, '084650040 1'));

    $result = app(AddProspects::class)($this->owner, $this->company, [prospectOrg(1)]);

    expect($result)->toBe(['added' => [], 'existing' => [], 'suppressed' => 1, 'missing' => 0])
        ->and(Account::query()->count())->toBe(0);
});

it('lets every CRM user search but only crm:manage add', function () {
    registryRow(1, 'Kodverket Exempel AB');
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $this->actingAs($auditor);

    Livewire::test(ProspectingPage::class)->set('division', '62')->assertSee('Kodverket Exempel AB')->assertDontSee('Lägg till som prospekt');

    expect(fn () => app(AddProspects::class)($auditor, $this->company, [prospectOrg(1)]))
        ->toThrow(AuthorizationException::class);
});

it('finds companies like the best customers on industry and town, and explains the match', function () {
    registryRow(1, 'Kundbolaget Exempel AB');
    registryRow(2, 'Tvillingen Exempel AB');
    registryRow(3, 'Annan stad Exempel AB', ['city' => 'MALMÖ']);
    registryRow(4, 'Annan bransch Exempel AB', ['sni_code' => '56110', 'sni_division' => '56']);
    registryRow(5, 'Grannbranschen Exempel AB', ['sni_code' => '62201']);
    $customer = Crm::customer($this->company, $this->owner, 'Kundbolaget Exempel AB', ['org_number' => prospectOrg(1)]);
    Crm::invoice($this->owner, $this->company, $customer, '2026-10-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '3000', 'vat_rate' => 2500]]);

    $best = app(LookAlike::class)->bestCustomers();
    expect($best->pluck('name')->all())->toBe(['Kundbolaget Exempel AB'])
        ->and($best->first()?->invoiced)->toBe(300_000);

    $page = Livewire::withQueryParams(['lage' => 'liknande'])->test(ProspectingPage::class)
        ->assertSee('Kundbolaget Exempel AB')
        ->assertSee('Tvillingen Exempel AB')
        ->assertSee('Liknar Kundbolaget Exempel AB: samma bransch (62100 Dataprogrammering), samma ort (Stockholm)')
        ->assertDontSee('Annan stad Exempel AB')->assertDontSee('Annan bransch Exempel AB')->assertDontSee('Grannbranschen Exempel AB')
        ->assertSee('Storleksklass kräver SCB:s API-nyckel');

    $page->set('likeSni', 'division')->assertSee('Grannbranschen Exempel AB');
    $page->set('likeRegion', false)->assertSee('Annan stad Exempel AB')->assertDontSee('Annan bransch Exempel AB');
});

it('uses size class and county when SCB\'s API has filled them', function () {
    RegistryImport::create(['source' => 'scb-api', 'status' => RegistryImport::DONE, 'started_at' => now(), 'finished_at' => now()]);
    registryRow(1, 'Kundbolaget Exempel AB', ['employee_class' => 4, 'county_code' => '01']);
    registryRow(2, 'Lika stort Exempel AB', ['employee_class' => 5, 'county_code' => '01', 'city' => 'SOLNA']);
    registryRow(3, 'För stort Exempel AB', ['employee_class' => 7, 'county_code' => '01']);
    registryRow(4, 'Annat län Exempel AB', ['employee_class' => 4, 'county_code' => '12']);
    $customer = Crm::customer($this->company, $this->owner, 'Kundbolaget Exempel AB', ['org_number' => prospectOrg(1)]);
    Crm::invoice($this->owner, $this->company, $customer, '2026-10-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500]]);

    Livewire::withQueryParams(['lage' => 'liknande'])->test(ProspectingPage::class)
        ->assertSee('Lika stort Exempel AB')
        ->assertSee('ungefär lika stort (10–19 anställda), samma län (Stockholms län)')
        ->assertDontSee('För stort Exempel AB')
        ->assertDontSee('Annat län Exempel AB');

    Livewire::withQueryParams([])->test(ProspectingPage::class)->set('employeesMin', '5')->assertSee('Lika stort Exempel AB')->assertSee('För stort Exempel AB')->assertDontSee('Annat län Exempel AB');
});

it('keeps the search at a fixed number of queries for 3 and 30 hits', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['huvudgrupp' => '62'])->test(ProspectingPage::class);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(1, 3) as $i) {
        registryRow($i, "Företag {$i} Exempel AB");
    }
    $count();
    $few = $count();

    foreach (range(4, 30) as $i) {
        registryRow($i, "Företag {$i} Exempel AB", ['employee_class' => 3]);
    }

    expect($count())->toBe($few);
});

it('works a call list: one tap logs the call, no answer goes last, and a next step is planned', function () {
    $first = Crm::prospect($this->company, $this->owner, 'Första Exempel AB', ['phone' => '08-465 004 02']);
    $second = Crm::prospect($this->company, $this->owner, 'Andra Exempel AB');
    $made = app(CreateCallList::class)($this->owner, $this->company, ['name' => 'Veckans samtal'], [$first->id, $second->id]);
    $list = $made['list'];

    $this->get(route('crm.call-lists.show', $list))->assertOk()->assertSee('Första Exempel AB')->assertSee('href="tel:0846500402"', escape: false)->assertSee('0 av 2 klara');

    $turn = fn (): CallListItem => CallListItem::query()->where('call_list_id', $list->id)->where('status', CallListItem::PENDING)->orderBy('position')->firstOrFail();
    $tap = fn ($page, string $outcome) => $page->call('call', $turn()->id, $turn()->attempts, $outcome);

    $page = Livewire::test(CallListPage::class, ['callListId' => $list->id])
        ->assertSeeHtml('wire:click="call('.$turn()->id.', 0, \'NO_ANSWER\')"');
    $tap($page, 'NO_ANSWER')->assertDispatched('toast');

    $item = CallListItem::query()->where('account_id', $first->id)->sole();
    expect($item->status)->toBe(CallListItem::PENDING)
        ->and($item->attempts)->toBe(1)
        ->and($item->position)->toBe(3);

    $page->assertSee('Andra Exempel AB')
        ->set('note', 'Vill ha offert')->set('nextType', 'TASK')->set('nextAt', '2026-11-05T09:00')->set('nextSubject', 'Skicka offert');
    $tap($page, 'REACHED');

    $call = Activity::query()->where('account_id', $second->id)->where('type', 'CALL')->sole();
    expect($call->call_outcome?->value)->toBe('REACHED')
        ->and($call->body)->toBe('Vill ha offert')
        ->and($call->done_at)->not->toBeNull()
        ->and(Activity::query()->where('account_id', $second->id)->where('type', 'TASK')->value('subject'))->toBe('Skicka offert')
        ->and(CallListItem::query()->where('account_id', $second->id)->value('status'))->toBe(CallListItem::DONE);

    // The third unanswered call ends the company on the list.
    $tap($page, 'LEFT_MESSAGE');
    $tap($page, 'NO_ANSWER');
    expect($item->fresh()?->status)->toBe(CallListItem::DONE)
        ->and($item->fresh()?->attempts)->toBe(3);

    $page->assertSee('Listan är klar')->assertSee('2 av 2 klara');
    Livewire::test(CallListsPage::class)->assertSee('Veckans samtal')->assertSee('2 av 2 klara');
});

it('refuses a call from a second tab that shows another company, or the same one with old attempts', function () {
    $first = Crm::prospect($this->company, $this->owner, 'Första Exempel AB');
    $second = Crm::prospect($this->company, $this->owner, 'Andra Exempel AB');
    $list = app(CreateCallList::class)($this->owner, $this->company, ['name' => 'Två flikar'], [$first->id, $second->id])['list'];
    $item = fn (Crm\Models\Account|Account $account): CallListItem => CallListItem::query()->where('account_id', $account->id)->sole();

    $tabOne = Livewire::test(CallListPage::class, ['callListId' => $list->id]);
    $tabTwo = Livewire::test(CallListPage::class, ['callListId' => $list->id]);
    $stale = $item($first);

    // Tab one logs "Inget svar": the first company goes last, attempts 1.
    $tabOne->call('call', $stale->id, 0, 'NO_ANSWER');

    // Tab two still shows the first company with 0 attempts: refused, with the message, and nothing logged.
    $tabTwo->call('call', $stale->id, 0, 'REACHED')
        ->assertHasErrors(['item' => 'Listan har ändrats i en annan flik. Sidan är uppdaterad – kontrollera vilket företag som står på tur.'])
        ->assertSee('Andra Exempel AB');
    $tabTwo->call('skip', $stale->id, 0)->assertHasErrors('item');

    // The same company again but with an old attempt count is also refused, until the page shows the current one.
    $tabOne->call('call', $item($second)->id, 0, 'NO_ANSWER');
    $tabTwo->call('call', $stale->id, 0, 'REACHED')->assertHasErrors('item');
    $tabTwo->call('call', $stale->id, 1, 'REACHED')->assertHasNoErrors();

    expect(Activity::query()->where('type', 'CALL')->count())->toBe(3)
        ->and($item($first)->status)->toBe(CallListItem::DONE)
        ->and($item($first)->outcome?->value)->toBe('REACHED');

    // Another list's item answers 404.
    $other = app(CreateCallList::class)($this->owner, $this->company, ['name' => 'Annan'], [Crm::prospect($this->company, $this->owner, 'Tredje Exempel AB')->id])['list'];
    $tabOne->call('call', CallListItem::query()->where('call_list_id', $other->id)->value('id'), 0, 'REACHED')->assertNotFound();
});

it('leaves private persons and sole traders out of call lists unless asked for, and warns about NIX', function () {
    $company = Crm::prospect($this->company, $this->owner, 'Företaget Exempel AB');
    $person = Crm::prospect($this->company, $this->owner, 'Anna Andersson', ['kind' => 'PERSON']);
    $soleTrader = Crm::accountFor(Crm::customer($this->company, $this->owner, 'Annas Firma', ['org_number' => '19800101-999'.Luhn::checkDigit('800101999')]));

    // A sole trader added by hand as a company, with the personal number on the account (an import, an older record).
    $byHand = Crm::prospect($this->company, $this->owner, 'Bertils Firma');
    $byHand->forceFill(['org_number' => '19800101-999'.Luhn::checkDigit('800101999')])->save();

    $made = app(CreateCallList::class)($this->owner, $this->company, ['name' => 'Utan privata'], [$company->id, $person->id, $soleTrader->id, $byHand->id]);
    expect($made['left_out'])->toBe(3)
        ->and(CallListItem::query()->where('call_list_id', $made['list']->id)->pluck('account_id')->all())->toBe([$company->id]);

    $with = app(CreateCallList::class)($this->owner, $this->company, ['name' => 'Med privata', 'include_private' => true], [$person->id, $soleTrader->id])['list'];
    Livewire::test(CallListPage::class, ['callListId' => $with->id])
        ->assertSee('Privatperson eller enskild firma: kontrollera numret mot NIX-Telefon innan du ringer i säljsyfte, om personen inte redan är kund.');
});

it('skips a company, refuses a person marked Kontakta inte, and leaves out phone opt-outs and lost accounts', function () {
    $account = Crm::prospect($this->company, $this->owner, 'Första Exempel AB', ['org_number' => prospectOrg(1)]);
    $blocked = Crm::prospect($this->company, $this->owner, 'Spärrat Exempel AB', ['org_number' => prospectOrg(2)]);
    $lost = Crm::prospect($this->company, $this->owner, 'Förlorat Exempel AB');
    $lost->forceFill(['status' => 'LOST'])->save();
    registryRow(2, 'Spärrat Exempel AB', ['phone_block' => 3]);
    $person = Crm::person($this->company, $this->owner, 'Karin Exempelsson');
    app(LinkPerson::class)($this->owner, $this->company, $account, $person, PersonRole::DecisionMaker);
    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Karin Exempelsson', 'legal_basis' => 'LEGITIMATE_INTEREST', 'do_not_contact' => true, 'do_not_contact_reason' => 'OBJECTION'], $person);

    $made = app(CreateCallList::class)($this->owner, $this->company, ['name' => 'Lista'], [$account->id, $blocked->id, $lost->id]);
    expect($made['left_out'])->toBe(2);
    $item = CallListItem::query()->sole();

    expect(fn () => app(WorkCallList::class)->call($this->owner, $this->company, $item, ['outcome' => 'REACHED', 'person_id' => $person->id]))
        ->toThrow(ValidationException::class);

    $stale = $item->fresh();
    app(WorkCallList::class)->skip($this->owner, $this->company, $item);
    expect($item->fresh()?->status)->toBe(CallListItem::SKIPPED);
    expect(fn () => app(WorkCallList::class)->skip($this->owner, $this->company, $item->fresh()))->toThrow(ValidationException::class);

    // A second tab still holding the item as pending: refused under the lock, no call logged.
    $stale?->forceFill(['status' => CallListItem::PENDING]);
    expect(fn () => app(WorkCallList::class)->call($this->owner, $this->company, $stale, ['outcome' => 'REACHED']))->toThrow(ValidationException::class)
        ->and(Activity::query()->where('type', 'CALL')->count())->toBe(0);

    Livewire::test(CallListPage::class, ['callListId' => $made['list']->id])
        ->call('archive')->assertDispatched('toast', message: 'Ringlistan är arkiverad.')
        ->call('archive')->assertDispatched('toast', message: 'Ringlistan är återställd.');
});

it('builds a call list from the CRM\'s own prospects', function () {
    Crm::prospect($this->company, $this->owner, 'Prospekt Ett AB', ['city' => 'Uppsala']);
    Crm::prospect($this->company, $this->owner, 'Prospekt Två AB', ['city' => 'Lund']);

    Livewire::test(CallListsPage::class)->call('start')
        ->set('form.name', 'Uppsala')->set('form.city', 'Uppsala')
        ->assertSee('1 företag kommer med.')
        ->call('create')
        ->assertRedirect(route('crm.call-lists.show', CallList::query()->firstOrFail()));

    expect(CallListItem::query()->count())->toBe(1);
});

it('turns register changes of a customer into one task for its owner, once, and only while monitoring is on', function () {
    $account = Crm::accountFor(Crm::customer($this->company, $this->owner, 'Kodverket Exempel AB', ['org_number' => prospectOrg(1)]));
    $prospect = Crm::prospect($this->company, $this->owner, 'Prospektet Exempel AB', ['org_number' => prospectOrg(3)]);
    $account->forceFill(['owner_id' => $this->owner->id])->save();
    $lastActivity = $account->fresh()?->last_activity_at?->toIso8601String();
    $this->travel(1)->hours();
    foreach ([['address_line1', 'Exempelgatan 1', 'Nya gatan 10'], ['proceedings', null, 'KK']] as [$field, $old, $new]) {
        RegistryChange::create(['org_number' => prospectOrg(1), 'field' => $field, 'old_value' => $old, 'new_value' => $new, 'source' => 'scb-bulk', 'detected_at' => now()]);
    }
    // A prospect's change waits until the company also watches prospects.
    RegistryChange::create(['org_number' => prospectOrg(3), 'field' => 'city', 'old_value' => 'STOCKHOLM', 'new_value' => 'LUND', 'source' => 'scb-bulk', 'detected_at' => now()]);
    // A change of another company, and one from before the account existed, are not reported.
    RegistryChange::create(['org_number' => prospectOrg(2), 'field' => 'name', 'old_value' => 'A', 'new_value' => 'B', 'source' => 'scb-bulk', 'detected_at' => now()]);
    RegistryChange::create(['org_number' => prospectOrg(1), 'field' => 'name', 'old_value' => 'A', 'new_value' => 'B', 'source' => 'scb-bulk', 'detected_at' => now()->subDays(3)]);

    $this->artisan('crm:registry-watch', ['company' => $this->company->id])->assertSuccessful();
    $this->artisan('crm:registry-watch', ['company' => $this->company->id])->assertSuccessful();

    $task = Activity::query()->where('account_id', $account->id)->sole();
    expect(Activity::query()->where('account_id', $prospect->id)->exists())->toBeFalse();
    expect($task->type->value)->toBe('TASK')
        ->and($task->subject)->toBe('Ändring hos kunden: ny adress, konkurs, likvidation eller rekonstruktion')
        ->and($task->body)->toContain('Ny adress: Exempelgatan 1 → Nya gatan 10')
        ->and($task->body)->toContain('Konkurs, likvidation eller rekonstruktion: – → Konkurs')
        ->and($task->assigned_to)->toBe($this->owner->id)
        ->and($task->due_at?->setTimezone('Europe/Stockholm')->format('H:i'))->toBe('09:00')
        ->and(RegistryNotice::query()->count())->toBe(2)
        ->and($account->fresh()?->last_activity_at?->toIso8601String())->toBe($lastActivity);

    RegistryChange::create(['org_number' => prospectOrg(1), 'field' => 'city', 'old_value' => 'STOCKHOLM', 'new_value' => 'UPPSALA', 'source' => 'scb-bulk', 'detected_at' => now()]);
    app(ModuleManager::class)->updateSettings(CrmModule::KEY, $this->company, $this->owner, [...CrmSettings::for($this->company), 'registry_monitoring' => 0]);
    $this->artisan('crm:registry-watch')->assertSuccessful();
    expect(Activity::query()->count())->toBe(1);

    expect(app(CreateRegistryNotices::class)($this->company))->toBe(1)
        ->and(Activity::query()->latest('id')->value('subject'))->toBe('Ändring hos kunden: ny ort');

    app(ModuleManager::class)->updateSettings(CrmModule::KEY, $this->company, $this->owner, [...CrmSettings::for($this->company), 'registry_monitoring_prospects' => 1]);
    expect(app(CreateRegistryNotices::class)($this->company))->toBe(1)
        ->and(Activity::query()->where('account_id', $prospect->id)->count())->toBe(1);
});

it('shows the register facts on the account card', function () {
    registryRow(1, 'Kodverket Exempel AB', ['proceedings' => 'LI', 'proceedings_since' => '2026-10-01', 'advertising_block' => true]);
    $account = Crm::prospect($this->company, $this->owner, 'Kodverket Exempel AB', ['org_number' => prospectOrg(1), 'sni_code' => '62100']);

    $this->get(route('crm.accounts.show', $account))->assertOk()
        ->assertSee('I företagsregistret')
        ->assertSee('Likvidation sedan 1 okt 2026')
        ->assertSee('Reklamspärr')
        ->assertSee('62100 Dataprogrammering')
        ->assertSee('Betalningsanmärkningar, kreditbetyg och styrelse kräver en kreditupplysningstjänst');
});

it('saves the monitoring setting from the CRM settings', function () {
    Livewire::test(CrmSettingsForm::class, ['company' => $this->company])
        ->assertSet('settings.registry_monitoring', true)
        ->assertSet('settings.registry_monitoring_prospects', false)
        ->set('settings.registry_monitoring', false)
        ->set('settings.registry_monitoring_prospects', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(CrmSettings::for($this->company)['registry_monitoring'])->toBe(0)
        ->and(CrmSettings::for($this->company)['registry_monitoring_prospects'])->toBe(1);
});

it('searches the whole register on part of a name, case-insensitively, without any other filter', function () {
    registryRow(1, 'Grönvåg Återvinning Exempel AB');
    registryRow(2, 'Kodverket Exempel AB');
    registryRow(3, 'Gamla Grönvåg Exempel AB', ['scb_status' => 9]);

    Livewire::test(ProspectingPage::class)->assertSee('Sök eller välj ett filter')
        ->set('search', 'grönvåg')
        ->assertSee('Grönvåg Återvinning Exempel AB')
        ->assertDontSee('Kodverket Exempel AB')
        ->assertDontSee('Gamla Grönvåg Exempel AB')
        ->assertSee('1 företag');

    Livewire::test(ProspectingPage::class)->set('search', 'ÅTERVINN')->assertSee('Grönvåg Återvinning Exempel AB');
    Livewire::test(ProspectingPage::class)->set('search', '  vÅg  ')->assertSee('Grönvåg Återvinning Exempel AB');
    Livewire::test(ProspectingPage::class)->set('search', 'Okänt namn')->assertSee('Inget företag matchar sökningen');
});

it('needs two characters before it searches on a name', function () {
    registryRow(1, 'Kodverket Exempel AB');

    Livewire::test(ProspectingPage::class)->set('search', 'k')
        ->assertSee('Skriv minst två tecken för att söka.')
        ->assertDontSee('Kodverket Exempel AB')
        ->assertSee('Sök eller välj ett filter');
});

it('searches on the start of an organisation number with or without a hyphen', function () {
    registryRow(1, 'Första Exempel AB');
    registryRow(12, 'Tolfte Exempel AB');
    $first = prospectOrg(1);

    Livewire::test(ProspectingPage::class)->set('search', substr($first, 0, 6).'-'.substr($first, 6))
        ->assertSee('Första Exempel AB')->assertDontSee('Tolfte Exempel AB');
    Livewire::test(ProspectingPage::class)->set('search', $first)
        ->assertSee('Första Exempel AB')->assertDontSee('Tolfte Exempel AB');
    Livewire::test(ProspectingPage::class)->set('search', '559999-61')
        ->assertSee('Tolfte Exempel AB')->assertDontSee('Första Exempel AB');
    Livewire::test(ProspectingPage::class)->set('search', '5599996')
        ->assertSee('Tolfte Exempel AB')->assertSee('Första Exempel AB');
    Livewire::test(ProspectingPage::class)->set('search', '4499')->assertSee('Inget företag matchar sökningen');
});

it('matches LIKE wildcards in the search literally', function () {
    registryRow(1, 'Hundra% Exempel AB');
    registryRow(2, 'Hundra procent Exempel AB');
    registryRow(3, 'Under_strecket Exempel AB');
    registryRow(4, 'Underxstrecket Exempel AB');
    registryRow(5, 'Snedstreck\\Exempel AB');

    Livewire::test(ProspectingPage::class)->set('search', 'a%')->assertSee('Hundra% Exempel AB')->assertDontSee('Hundra procent Exempel AB');
    Livewire::test(ProspectingPage::class)->set('search', 'r_s')->assertSee('Under_strecket Exempel AB')->assertDontSee('Underxstrecket Exempel AB');
    Livewire::test(ProspectingPage::class)->set('search', '%%')->assertSee('Inget företag matchar sökningen');
    Livewire::test(ProspectingPage::class)->set('search', 'k\\E')->assertSee('Snedstreck\\Exempel AB')->assertDontSee('Hundra% Exempel AB');
});

it('combines the search with the filters and with look-alike', function () {
    registryRow(1, 'Kundbolaget Exempel AB');
    registryRow(2, 'Norrsken Data Exempel AB');
    registryRow(3, 'Norrsken Bygg Exempel AB', ['sni_code' => '41200', 'sni_division' => '41']);
    registryRow(4, 'Söderljus Data Exempel AB');

    Livewire::withQueryParams(['sok' => 'norrsken', 'huvudgrupp' => '62'])->test(ProspectingPage::class)
        ->assertSee('Norrsken Data Exempel AB')
        ->assertDontSee('Norrsken Bygg Exempel AB')
        ->assertDontSee('Söderljus Data Exempel AB')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSee('Sök eller välj ett filter');

    $customer = Crm::customer($this->company, $this->owner, 'Kundbolaget Exempel AB', ['org_number' => prospectOrg(1)]);
    Crm::invoice($this->owner, $this->company, $customer, '2026-10-01', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500]]);

    Livewire::withQueryParams(['lage' => 'liknande'])->test(ProspectingPage::class)
        ->assertSee('Norrsken Data Exempel AB')->assertSee('Söderljus Data Exempel AB')
        ->set('search', 'söder')
        ->assertSee('Söderljus Data Exempel AB')->assertDontSee('Norrsken Data Exempel AB');
});

it('keeps the search and the page size in the query string', function () {
    registryRow(1, 'Norrsken Data Exempel AB');

    Livewire::withQueryParams(['sok' => 'norrsken', 'per-sida' => '100'])->test(ProspectingPage::class)
        ->assertSet('search', 'norrsken')
        ->assertSet('perPage', '100')
        ->assertSee('Norrsken Data Exempel AB');

    $this->get('/kunder/prospektera?sok=norrsken&per-sida=50')->assertOk()
        ->assertSee('Norrsken Data Exempel AB')
        ->assertSee('Visa per sida');
});

it('shows 25, 50, 100 or 200 per page and falls back to 25 for anything else', function () {
    foreach (range(1, 60) as $i) {
        registryRow($i, sprintf('Bolag %02d Exempel AB', $i));
    }

    Livewire::test(ProspectingPage::class)->set('division', '62')
        ->assertSee('Bolag 25 Exempel AB')->assertDontSee('Bolag 26 Exempel AB')
        ->set('perPage', '50')
        ->assertSet('perPage', '50')
        ->assertSee('Bolag 50 Exempel AB')->assertDontSee('Bolag 51 Exempel AB')
        ->set('perPage', '100')
        ->assertSee('Bolag 60 Exempel AB')
        ->set('perPage', '37')
        ->assertSet('perPage', '25')
        ->assertDontSee('Bolag 26 Exempel AB');

    foreach (['13', 'abc', '-50', '1000', ''] as $invalid) {
        Livewire::withQueryParams(['huvudgrupp' => '62', 'per-sida' => $invalid])->test(ProspectingPage::class)
            ->assertSet('perPage', '25')
            ->assertSee('Bolag 25 Exempel AB')->assertDontSee('Bolag 26 Exempel AB');
    }
});

it('adds the ticked and all hits of a search shown with a larger page', function () {
    foreach (range(1, 30) as $i) {
        registryRow($i, sprintf('Norrsken %02d Exempel AB', $i));
    }
    registryRow(31, 'Söderljus Exempel AB');

    Livewire::withQueryParams(['sok' => 'norrsken', 'per-sida' => '50'])->test(ProspectingPage::class)
        ->assertSee('Norrsken 30 Exempel AB')
        ->set('selected', [prospectOrg(30)])
        ->call('addSelected')
        ->call('addAll');

    expect(Account::query()->where('name', 'like', 'Norrsken%')->count())->toBe(30)
        ->and(Account::query()->where('name', 'Söderljus Exempel AB')->exists())->toBeFalse();
});

it('keeps a fixed number of queries with a search and 200 per page', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['sok' => 'exempel', 'per-sida' => '200'])->test(ProspectingPage::class);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(1, 3) as $i) {
        registryRow($i, "Företag {$i} Exempel AB");
    }
    $count();
    $few = $count();

    foreach (range(4, 60) as $i) {
        registryRow($i, "Företag {$i} Exempel AB", ['employee_class' => 3]);
    }

    expect($count())->toBe($few);
});
