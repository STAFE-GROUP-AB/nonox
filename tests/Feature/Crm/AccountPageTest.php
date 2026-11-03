<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Models\SalesDocument;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Livewire\AccountCard;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * The account card's new layout (ADR-043): the shared header with quick
 * actions and facts, the status path with dates or an open deal's
 * pipeline, the key figures band, tabs with counts (timeline, activity
 * types, deals, people, documents for those who may see them), and a fixed
 * number of queries.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB', ['city' => 'Lund', 'phone' => '08-123 456 78', 'email' => 'info@kontorshuset.test']);
    $this->account = Crm::accountFor($this->customer);
});

it('shows the header with the quick actions and the facts, and the status path with the first invoice\'s date', function () {
    Crm::invoice($this->owner, $this->company, $this->customer, '2026-03-02', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500]]);
    $this->artisan('crm:refresh-statuses')->assertSuccessful();

    $this->get(route('crm.accounts.show', $this->account))->assertOk()
        ->assertSee('Kontorshuset AB')
        ->assertSee('Lund')
        ->assertSee('href="tel:0812345678"', escape: false)
        ->assertSee('href="mailto:info@kontorshuset.test"', escape: false)
        ->assertSeeInOrder(['Ring', 'Mejla', 'Logga samtal', 'Anteckning', 'Planera möte', 'Uppgift', 'Ny affär', 'Ny offert'])
        ->assertSeeInOrder(['Kundnummer', 'Org.nr', 'Ansvarig', 'Källa', 'Bransch (SNI)', 'Koncern', 'Kund sedan', 'Senaste kontakt'])
        ->assertSeeInOrder(['Prospekt', 'Aktiv kund', '2 mar 2026', 'Inaktiv', 'Förlorad'])
        ->assertSee('Kunden i siffror')
        ->assertSee('Fakturerat 12 mån');
});

it('switches the stepper to an open deal\'s pipeline', function () {
    $deal = Crm::deal($this->company, $this->owner, $this->account, 'Ramavtal', extra: ['stage_id' => Crm::stage(1)->id]);

    Livewire::test(AccountCard::class, ['accountId' => $this->account->id])
        ->assertSee('Kundens status')
        ->set('path', (string) $deal->id)
        ->assertSee('Steg i säljtratten')
        ->assertSeeHtml('aria-current="step"')
        ->assertSee('Förhandling');
});

it('filters the tabs by type and lists deals, people and documents', function () {
    $log = app(LogActivity::class);
    $log($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'NOTE', 'subject' => 'En anteckning']);
    $log($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'CALL', 'subject' => 'Ett samtal', 'done' => true]);
    $person = Crm::person($this->company, $this->owner, 'Anna Andersson');
    app(LinkPerson::class)($this->owner, $this->company, $this->account, $person, PersonRole::DecisionMaker);
    Crm::deal($this->company, $this->owner, $this->account, 'Ramavtal');
    app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, ['customer_id' => $this->customer->id, 'document_date' => '2026-06-10', 'rows' => [['description' => 'Tjänst', 'quantity' => '1', 'price' => '500', 'vat_rate' => 2500]]]);

    Livewire::test(AccountCard::class, ['accountId' => $this->account->id])
        ->assertSee('En anteckning')->assertSee('Ett samtal')->assertSee('Offert 1')
        ->set('tab', 'anteckningar')->assertSee('En anteckning')->assertDontSee('Ett samtal')->assertDontSee('Offert 1')
        ->set('tab', 'dokument')->assertSee('Offert 1')->assertDontSee('En anteckning')
        ->set('tab', 'affarer')->assertSee('Ramavtal')
        ->set('tab', 'personer')->assertSee('Anna Andersson')->assertSee('Beslutsfattare')->assertSeeHtml(route('crm.people.show', $person));
});

it('shows no documents tab to a seller, and the same quick actions without those a seller may not use', function () {
    $this->actingAs(Bookkeeping::member($this->company, 'sales'));

    Livewire::test(AccountCard::class, ['accountId' => $this->account->id])
        ->assertDontSeeHtml('crm-account-tab-dokument')
        ->assertSee('Logga samtal')
        ->assertDontSee('Ny offert')
        ->assertDontSee('Gör till kund');
});

it('renders the card with a fixed number of queries for a short and a long history', function () {
    $count = function (): int {
        Livewire::test(AccountCard::class, ['accountId' => $this->account->id]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(AccountCard::class, ['accountId' => $this->account->id]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'NOTE', 'subject' => 'En']);
    Crm::deal($this->company, $this->owner, $this->account, 'Första');
    $person = Crm::person($this->company, $this->owner, 'Första Person');
    app(LinkPerson::class)($this->owner, $this->company, $this->account, $person, PersonRole::DecisionMaker);
    $few = $count();

    foreach (range(1, 20) as $i) {
        app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => ['NOTE', 'CALL', 'MEETING', 'TASK'][$i % 4], 'subject' => "Aktivitet {$i}", 'done' => $i % 2 === 0]);
    }
    foreach (range(1, 5) as $i) {
        Crm::deal($this->company, $this->owner, $this->account, "Affär {$i}");
        app(LinkPerson::class)($this->owner, $this->company, $this->account, Crm::person($this->company, $this->owner, "Person {$i}"), PersonRole::User);
    }

    expect($count())->toBe($few);
});
