<?php

declare(strict_types=1);

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\ConsolidateGroup;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\GrantGroupRole;
use App\Actions\Group\RecordHolding;
use App\Domain\Group\ControlBasis;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupReport;
use App\Livewire\Group\GroupReportsPage;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Group reports never leak (ADR-081 E26, AGENTS.md §1.3). Olle's group is P
 * with subsidiary A. A stranger's group is Q with subsidiary R, with a run
 * of its own. A run id of another group answers 404 in the files and is
 * never shown on the page; a member of one subsidiary only, and a member of
 * the parent limited to cost centres even with the group role, see nothing.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00'));
    $this->olle = User::factory()->withCompany()->create();
    $this->p = $this->olle->currentCompany();
    app(InitialiseBookkeeping::class)($this->p, $this->olle, CarbonImmutable::parse('2026-01-15'));
    $this->a = e26TenancyCompany($this->olle);
    e26TenancyHolding($this->olle, $this->p, $this->a);
    $this->group = app(CreateCorporateGroup::class)($this->olle->fresh(), $this->p, 'Koncernen');
    app(ConfirmGroupMember::class)($this->olle->fresh(), $this->group->id, $this->a);
    CurrentCompany::run($this->a, fn () => Bookkeeping::postRows($this->olle->fresh(), $this->a, '2026-03-01', [1930 => 3_000, 3001 => -3_000]));

    $this->stranger = User::factory()->withCompany()->create();
    $this->q = $this->stranger->currentCompany();
    $this->q->forceFill(['name' => 'Främmande Moder AB'])->save();
    app(InitialiseBookkeeping::class)($this->q, $this->stranger, CarbonImmutable::parse('2026-01-15'));
    $this->r = e26TenancyCompany($this->stranger);
    e26TenancyHolding($this->stranger, $this->q, $this->r);
    $this->otherGroup = app(CreateCorporateGroup::class)($this->stranger->fresh(), $this->q, 'Främmande koncernen');
    app(ConfirmGroupMember::class)($this->stranger->fresh(), $this->otherGroup->id, $this->r);
    CurrentCompany::run($this->r, fn () => Bookkeeping::postRows($this->stranger->fresh(), $this->r, '2026-03-01', [1930 => 1_600_000, 3001 => -1_600_000]));

    $this->run = app(ConsolidateGroup::class)($this->olle->fresh(), $this->group->id, ['from' => '2026-01-01', 'to' => '2026-12-31']);
    $this->foreign = app(ConsolidateGroup::class)($this->stranger->fresh(), $this->otherGroup->id, ['from' => '2026-01-01', 'to' => '2026-12-31']);
    $this->olle = $this->olle->fresh();
});

function e26TenancyCompany(User $admin): Company
{
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    app(InitialiseBookkeeping::class)($company, $owner, CarbonImmutable::parse('2026-01-15'));
    $company->users()->attach($admin, ['role' => 'admin']);

    return $company;
}

function e26TenancyHolding(User $user, Company $holder, Company $held): void
{
    app(RecordHolding::class)($user->fresh(), $holder, [
        'held_org_number' => $held->org_number, 'held_name' => $held->name, 'capital' => '100', 'voting' => '100',
        'control_basis' => ControlBasis::Votes->value, 'acquired_on' => '2024-01-01',
    ]);
}

it('answers 404 for another group\'s run in the CSV, the PDF and the report', function () {
    $this->actingAs($this->olle);

    $this->get(route('group.reports.csv', ['run' => $this->foreign->id]))->assertNotFound();
    $this->get(route('group.reports.pdf', ['run' => $this->foreign->id]))->assertNotFound();
    $this->get(route('group.reports', ['koncern' => $this->otherGroup->id]))->assertNotFound();

    expect(fn () => GroupReport::build(GroupAccess::for($this->olle), $this->foreign))->toThrow(ModelNotFoundException::class);
});

it('never shows another group\'s run through one\'s own group on the page', function () {
    $this->actingAs($this->olle);

    $this->get(route('group.reports', ['koncern' => $this->group->id, 'korning' => $this->foreign->id]))
        ->assertOk()
        ->assertSee('Intern koncernrapport 2026-01-01 – 2026-12-31')
        ->assertDontSee('Främmande')
        ->assertDontSee('16 000,00');

    Livewire::test(GroupReportsPage::class)
        ->call('selectRun', (string) $this->foreign->id)
        ->assertDontSee('Främmande')
        ->assertDontSee('16 000,00');
});

it('shows nothing to a member of one subsidiary only, nor to a limited member of the parent with the group role', function () {
    $partial = Bookkeeping::member($this->a, 'accountant');
    $this->actingAs($partial);

    $this->get(route('group.reports'))->assertOk()->assertSee('Du ser ingen koncern')->assertDontSee('Koncernen ·');
    $this->get(route('group.reports.csv', ['run' => $this->run->id]))->assertNotFound();
    $this->get(route('group.reports.pdf', ['run' => $this->run->id]))->assertNotFound();

    $limited = Bookkeeping::member($this->p, 'bookkeeper');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $limited->id);

    // The run has two companies for the whole year, so the role alone sees its sums, without the breakdown.
    expect(GroupReport::build(GroupAccess::for($limited->fresh()), $this->run)['detailed'])->toBeFalse();

    $this->p->users()->updateExistingPivot($limited->id, ['dimension_scoped' => true]);

    expect(fn () => GroupReport::build(GroupAccess::for($limited->fresh()), $this->run))->toThrow(ModelNotFoundException::class);
});
