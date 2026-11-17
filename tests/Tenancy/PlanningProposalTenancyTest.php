<?php

declare(strict_types=1);

use App\Modules\ModuleManager;
use App\Modules\Planning\Actions\MakeProposal;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Actions\SaveBudget;
use App\Modules\Planning\Actions\SaveSalesSource;
use App\Modules\Planning\Actions\SaveStaffingRule;
use App\Modules\Planning\Livewire\ProposalPage;
use App\Modules\Planning\Models\Proposal;
use App\Modules\Planning\Models\ProposalItem;
use App\Modules\Planning\Models\Shift;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\Pos\Models\PosSale;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * Schedule proposals (ADR-037 amendment, gap 3) belong to one company:
 * another company's proposal answers 404 on /planering/forslag, its items
 * are invisible, and a proposal never sees another company's staff or open
 * shifts. Fairness is measured inside one company only.
 */

it('never shows another company\'s proposal, items, staff or open shifts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Europe/Stockholm'));
    [$ownerA, $companyA] = Bookkeeping::company();
    [$ownerB, $companyB] = Bookkeeping::company();
    Planning::setUp($companyA, $ownerA);
    app(ModuleManager::class)->enable('payroll', $companyB, $ownerB);
    app(ModuleManager::class)->enable('planning', $companyB, $ownerB);

    $annaB = Planning::employee($ownerB, $companyB, 'Anna', '900615123');
    Planning::shift($ownerB, $companyB, ['employee_id' => null, 'date' => '2026-11-24', 'start' => '09:00', 'end' => '17:00', 'break_minutes' => 30]);
    $proposalB = CurrentCompany::run($companyB, fn (): Proposal => app(MakeProposal::class)($ownerB, $companyB, ['from' => '2026-11-23', 'to' => '2026-11-29']));

    $itemB = CurrentCompany::run($companyB, fn (): ProposalItem => ProposalItem::query()->sole());
    expect($itemB->employee_id)->toBe($annaB->id);

    // Company A has staff but no open shifts: B's shift and B's staff never enter A's proposal.
    Planning::employee($ownerA, $companyA, 'Bertil', '850301234');
    $proposalA = CurrentCompany::run($companyA, fn (): Proposal => app(MakeProposal::class)($ownerA, $companyA, ['from' => '2026-11-23', 'to' => '2026-11-29']));

    expect($proposalA->slot_count)->toBe(0);

    CurrentCompany::set($companyA);
    expect(Proposal::query()->find($proposalB->id))->toBeNull()
        ->and(ProposalItem::query()->find($itemB->id))->toBeNull();

    $this->actingAs($ownerA)->get(route('planning.proposals', ['forslag' => $proposalB->id]))->assertNotFound();
    $this->actingAs($ownerA)->get(route('planning.proposals'))->assertOk()->assertDontSee('Anna');
});

it('makes new shifts only from the company\'s own demand, areas and budget', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Europe/Stockholm'));
    [$ownerA, $companyA] = Bookkeeping::company();
    [$ownerB, $companyB] = Bookkeeping::company();
    Planning::setUp($companyA, $ownerA);
    app(ModuleManager::class)->enable('payroll', $companyB, $ownerB);
    app(ModuleManager::class)->enable('planning', $companyB, $ownerB);
    Planning::employee($ownerA, $companyA, 'Anna', '900615123');
    Planning::employee($ownerB, $companyB, 'Bertil', '850301234');

    // Company B has an area with a staffing target; company A has none.
    $areaB = CurrentCompany::run($companyB, fn () => app(SaveArea::class)($ownerB, $companyB, ['name' => 'Kök', 'colour' => '#e4572e']));
    CurrentCompany::run($companyB, fn () => app(SaveStaffingRule::class)($ownerB, $companyB, ['area_id' => $areaB->id, 'weekday' => 2, 'start' => '11:00', 'end' => '14:00', 'min_staff' => 1, 'valid_from' => '2026-11-01']));

    $proposalA = CurrentCompany::run($companyA, fn (): Proposal => app(MakeProposal::class)($ownerA, $companyA, ['from' => '2026-11-23', 'to' => '2026-11-29', 'new_shifts' => true]));
    $proposalB = CurrentCompany::run($companyB, fn (): Proposal => app(MakeProposal::class)($ownerB, $companyB, ['from' => '2026-11-23', 'to' => '2026-11-29', 'new_shifts' => true]));

    expect($proposalA->slot_count)->toBe(0)
        ->and($proposalB->slot_count)->toBe(1);

    $itemB = CurrentCompany::run($companyB, fn (): ProposalItem => ProposalItem::query()->sole());
    expect($itemB->area_id)->toBe($areaB->id);

    CurrentCompany::set($companyA);
    expect(ProposalItem::query()->find($itemB->id))->toBeNull();
    $this->actingAs($ownerA)->get(route('planning.proposals', ['forslag' => $proposalB->id]))->assertNotFound();
});

it('never lets another company\'s budget, till sales or proposal into a proposal with new shifts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Europe/Stockholm'));
    [$ownerA, $companyA] = Bookkeeping::company();
    [$ownerB, $companyB] = Bookkeeping::company();
    Planning::setUp($companyA, $ownerA);
    app(ModuleManager::class)->enable('payroll', $companyB, $ownerB);
    app(ModuleManager::class)->enable('planning', $companyB, $ownerB);
    Planning::employee($ownerA, $companyA, 'Anna', '900615123', ['salary_form' => 'HOURLY', 'salary' => '150']);
    Planning::employee($ownerB, $companyB, 'Bertil', '850301234', ['salary_form' => 'HOURLY', 'salary' => '150']);

    // Company A: a floor on Tuesdays, and books sales with a target per day only on Wednesdays (no till, no hour profile).
    CurrentCompany::run($companyA, fn () => app(SaveStaffingRule::class)($ownerA, $companyA, ['weekday' => 2, 'start' => '11:00', 'end' => '14:00', 'min_staff' => 1, 'valid_from' => '2026-11-01']));
    CurrentCompany::run($companyA, fn () => app(SaveStaffingRule::class)($ownerA, $companyA, ['weekday' => 3, 'start' => '10:00', 'end' => '18:00', 'sales_per_staff_hour' => '1 000', 'valid_from' => '2026-11-01']));
    Bookkeeping::post($ownerA, $companyA, 900000, 1930, 3001, '2026-11-11');

    // Company B: a budget of 1 kr, a till mapped to the whole company with a receipt on a Wednesday, and a proposal of its own.
    CurrentCompany::run($companyB, fn () => app(SaveBudget::class)($ownerB, $companyB, ['from' => '2026-11-23', 'kind' => SaveBudget::AMOUNT, 'value' => '1']));
    $till = CurrentCompany::run($companyB, fn () => PosConnection::create([
        'provider' => 'zettle', 'status' => 'ACTIVE', 'external_org_id' => 'demo-'.Str::random(8), 'display_name' => 'Kassan', 'credentials' => [],
        'webhook_token' => Str::random(48), 'settings' => [], 'connected_by' => $ownerB->id, 'connected_at' => now(),
    ]));
    CurrentCompany::run($companyB, fn () => app(SaveSalesSource::class)($ownerB, $companyB, $till->id, SaveSalesSource::COMPANY));
    CurrentCompany::run($companyB, fn () => PosSale::create([
        'pos_connection_id' => $till->id, 'provider' => 'zettle', 'external_id' => (string) Str::uuid(), 'source' => 'WEBHOOK', 'is_refund' => false,
        'sold_at' => CarbonImmutable::parse('2026-11-11 12:15', 'Europe/Stockholm'), 'business_date' => '2026-11-11', 'currency' => 'SEK',
        'total_ore' => 500000, 'vat_ore' => 100000, 'tips_ore' => 0, 'vat_totals' => [], 'payments' => [], 'payload' => [], 'payload_hash' => str_repeat('b', 64),
    ]));
    Planning::shift($ownerB, $companyB, ['employee_id' => null, 'date' => '2026-11-24', 'start' => '09:00', 'end' => '17:00', 'break_minutes' => 30]);
    $proposalB = CurrentCompany::run($companyB, fn (): Proposal => app(MakeProposal::class)($ownerB, $companyB, ['from' => '2026-11-23', 'to' => '2026-11-29']));

    $proposalA = CurrentCompany::run($companyA, fn (): Proposal => app(MakeProposal::class)($ownerA, $companyA, ['from' => '2026-11-23', 'to' => '2026-11-29', 'new_shifts' => true]));

    // B's 1 kr budget does not limit A, and B's till gives A's Wednesday no hours: it stays per day only.
    expect($proposalA->parameters['over_budget'])->toBe(0)
        ->and($proposalA->parameters['new_count'])->toBe(1)
        ->and(array_keys($proposalA->parameters['unplaced']))->toBe(['2026-11-25']);

    // B's proposal cannot be opened from A (404), and B's item id ticked on A's proposal accepts nothing.
    CurrentCompany::set($companyA);
    $this->actingAs($ownerA);
    $this->get(route('planning.proposals', ['forslag' => $proposalB->id]))->assertNotFound();
    $itemB = CurrentCompany::run($companyB, fn (): ProposalItem => ProposalItem::query()->where('proposal_id', $proposalB->id)->sole());
    Livewire::withQueryParams(['forslag' => $proposalA->id])->test(ProposalPage::class)->set('selected', [$itemB->id])->call('acceptSelected')
        ->assertDispatched('toast', message: 'Markera minst ett pass med ett förslag.', type: 'error');
    expect(CurrentCompany::run($companyB, fn () => Shift::query()->whereNull('employee_id')->count()))->toBe(1);
});
