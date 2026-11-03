<?php

declare(strict_types=1);

use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\RefreshStatuses;
use App\Modules\Crm\Domain\AccountFigures;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Domain\Timeline;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\AccountsPage;
use App\Modules\Crm\Livewire\ActivitiesPage;
use App\Modules\Crm\Livewire\PeoplePage;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Connect;
use Tests\Support\Crm;

/*
 * AGENTS.md §13.2 for CRM (ADR-043): company A never sees B's accounts,
 * people, links, activities, key figures or timeline, through Eloquent, the
 * pages, the register extract, the API or the daily command; B's ids
 * answer 404.
 */

beforeEach(function () {
    Connect::setUp();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Connect::company();
        $this->actingAs($owner);
        Crm::enable($company, $owner);
        $customer = Crm::customer($company, $owner, "{$name}kunden AB");
        Crm::invoice($owner, $company, $customer, '2026-06-01', [['description' => "{$name}tjänst", 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500]]);
        $account = Crm::accountFor($customer);
        $person = Crm::person($company, $owner, "{$name} Kontaktsson");
        app(LinkPerson::class)($owner, $company, $account, $person, PersonRole::DecisionMaker);
        $activity = app(LogActivity::class)($owner, $company, ['account_id' => $account->id, 'person_id' => $person->id, 'type' => 'TASK', 'subject' => "{$name}uppgift", 'due_at' => '2026-06-14T09:00']);
        $this->{$side} = compact('owner', 'company', 'customer', 'account', 'person', 'activity');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('never shows B\'s CRM records through Eloquent, the figures or the timeline', function () {
    expect(Account::query()->pluck('name')->all())->toBe(['Alfakunden AB'])
        ->and(Person::query()->pluck('name')->all())->toBe(['Alfa Kontaktsson'])
        ->and(Activity::query()->pluck('subject')->all())->toBe(['Alfauppgift'])
        ->and(app(AccountFigures::class)->forCustomers([$this->a['customer']->id, $this->b['customer']->id]))->toHaveKeys([$this->a['customer']->id])
        ->and(app(AccountFigures::class)->forCustomers([$this->b['customer']->id]))->toBe([]);

    // B's account object, read here, shows nothing of B on A's side.
    $foreign = clone $this->b['account'];
    expect(app(Timeline::class)->for($foreign)->total())->toBe(0);
});

it('shows only A\'s records on the pages, and answers 404 for B\'s card and B\'s ids', function () {
    $this->get(route('invoicing.customers'))->assertOk()->assertSee('Alfakunden AB')->assertDontSee('Betakunden AB');
    $this->get(route('crm.activities'))->assertOk()->assertSee('Alfauppgift')->assertDontSee('Betauppgift');
    $this->get(route('crm.people'))->assertOk()->assertSee('Alfa Kontaktsson')->assertDontSee('Beta Kontaktsson');
    $this->get(route('crm.accounts.show', $this->a['account']))->assertOk();
    $this->get(route('crm.accounts.show', $this->b['account']))->assertNotFound();
    $this->get(route('privacy.export.crmPerson', $this->b['person']))->assertNotFound();
    $this->get(route('invoicing.customers', ['redigera' => $this->b['customer']->id]))->assertOk()->assertDontSee('Betakunden AB');

    Livewire::test(AccountsPage::class)->set('search', 'Beta')->assertDontSee('Betakunden AB');
    Livewire::test(ActivitiesPage::class)->call('done', $this->b['activity']->id)->assertNotFound();
    Livewire::test(PeoplePage::class)->call('startPerson', $this->b['person']->id)->assertNotFound();
    Livewire::test(AccountCard::class, ['accountId' => $this->a['account']->id])
        ->set('linkPersonId', (string) $this->b['person']->id)
        ->call('link')
        ->assertNotFound();
});

it('shows only A\'s CRM records over the API, and 404 for B\'s ids', function () {
    $key = Connect::apiKey($this->a['owner'], $this->a['company'], ['crm:view', 'crm:manage']);

    $this->withToken($key)->getJson('/api/v1/crm/accounts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Alfakunden AB');
    $this->withToken($key)->getJson('/api/v1/crm/people')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($key)->getJson('/api/v1/crm/activities')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($key)->getJson("/api/v1/crm/accounts/{$this->b['account']->id}")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/crm/people/{$this->b['person']->id}")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/crm/activities/{$this->b['activity']->id}")->assertNotFound();
    $this->withToken($key)->patchJson("/api/v1/crm/accounts/{$this->b['account']->id}", ['source' => 'X'])->assertNotFound();
    $this->withToken($key)->withHeader('Idempotency-Key', 'tvars-1')
        ->postJson('/api/v1/crm/activities', ['account_id' => $this->b['account']->id, 'type' => 'NOTE'])
        ->assertUnprocessable();
});

it('runs the status rules of one company without touching the other', function () {
    app(RefreshStatuses::class)($this->a['company']);

    CurrentCompany::set($this->b['company']);
    expect(Account::query()->firstOrFail()->status->value)->toBe('PROSPECT');

    CurrentCompany::set($this->a['company']);
    expect(Account::query()->firstOrFail()->status->value)->toBe('ACTIVE');
});
