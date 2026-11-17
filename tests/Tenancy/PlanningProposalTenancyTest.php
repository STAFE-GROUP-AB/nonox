<?php

declare(strict_types=1);

use App\Modules\ModuleManager;
use App\Modules\Planning\Actions\MakeProposal;
use App\Modules\Planning\Models\Proposal;
use App\Modules\Planning\Models\ProposalItem;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
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
