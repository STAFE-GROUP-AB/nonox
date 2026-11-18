<?php

declare(strict_types=1);

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\ConsolidateGroup;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\MapGroupAccount;
use App\Actions\Group\RecordHolding;
use App\Domain\Group\ControlBasis;
use App\Livewire\Group\ConsolidationPage;
use App\Models\Company;
use App\Models\CorporateGroupRun;
use App\Models\GroupAccountMap;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Consolidation never leaks (ADR-081 E24, AGENTS.md §1.3). Group 1 is P with
 * subsidiary A. Outside it: company X, where the same administrator is a
 * member and which has an identical chart and a ledger of its own, and a
 * second group Q with subsidiary R that the administrator has nothing to do
 * with. Only confirmed members are ever read; nobody outside the group gets
 * anything but 404; and the current company is the same after a run.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00'));
    $this->olle = User::factory()->withCompany()->create();
    $this->p = $this->olle->currentCompany();
    app(InitialiseBookkeeping::class)($this->p, $this->olle, CarbonImmutable::parse('2026-01-15'));

    $this->a = e24TenancyCompany($this->olle);
    $this->x = e24TenancyCompany($this->olle);

    e24TenancyHolding($this->olle, $this->p, $this->a);
    // X is held by P but never confirmed as a member: a holding alone never brings a company into the figures.
    e24TenancyHolding($this->olle, $this->p, $this->x);
    $this->group = app(CreateCorporateGroup::class)($this->olle->fresh(), $this->p, 'Koncernen');
    app(ConfirmGroupMember::class)($this->olle->fresh(), $this->group->id, $this->a);

    $this->stranger = User::factory()->withCompany()->create();
    $this->q = $this->stranger->currentCompany();
    app(InitialiseBookkeeping::class)($this->q, $this->stranger, CarbonImmutable::parse('2026-01-15'));
    $this->r = e24TenancyCompany($this->stranger);
    e24TenancyHolding($this->stranger, $this->q, $this->r);
    $this->otherGroup = app(CreateCorporateGroup::class)($this->stranger->fresh(), $this->q, 'Främmande koncernen');
    app(ConfirmGroupMember::class)($this->stranger->fresh(), $this->otherGroup->id, $this->r);

    foreach ([[$this->olle, $this->p, 1_000], [$this->olle, $this->a, 2_000], [$this->olle, $this->x, 400_000], [$this->stranger, $this->q, 800_000], [$this->stranger, $this->r, 1_600_000]] as [$user, $company, $ore]) {
        CurrentCompany::run($company, fn () => Bookkeeping::postRows($user->fresh(), $company, '2026-03-01', [1930 => $ore, 3001 => -$ore]));
    }

    $this->olle = $this->olle->fresh();
});

function e24TenancyCompany(User $admin): Company
{
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    app(InitialiseBookkeeping::class)($company, $owner, CarbonImmutable::parse('2026-01-15'));
    $company->users()->attach($admin, ['role' => 'admin']);

    return $company;
}

function e24TenancyHolding(User $user, Company $holder, Company $held): void
{
    app(RecordHolding::class)($user->fresh(), $holder, [
        'held_org_number' => $held->org_number, 'held_name' => $held->name, 'capital' => '100', 'voting' => '100',
        'control_basis' => ControlBasis::Votes->value, 'acquired_on' => '2024-01-01',
    ]);
}

it('reads only the confirmed members, never another company the user belongs to or another group\'s', function () {
    $run = app(ConsolidateGroup::class)($this->olle, $this->group->id, ['from' => '2026-01-01', 'to' => '2026-12-31']);
    $read = array_column($run->inputs['companies'], 'company_id');

    expect($read)->toEqualCanonicalizing([$this->p->id, $this->a->id])
        ->and($read)->not->toContain($this->x->id)
        ->and($run->output['income']['result'])->toBe(3_000);
});

it('restores the current company after a run', function () {
    CurrentCompany::set($this->x);

    app(ConsolidateGroup::class)($this->olle, $this->group->id, ['from' => '2026-01-01', 'to' => '2026-12-31']);

    expect(CurrentCompany::id())->toBe($this->x->id);
});

it('answers 404 for another group\'s consolidation, runs and page', function () {
    $foreign = app(ConsolidateGroup::class)($this->stranger->fresh(), $this->otherGroup->id, ['from' => '2026-01-01', 'to' => '2026-12-31']);

    expect(fn () => app(ConsolidateGroup::class)($this->olle, $this->otherGroup->id, ['from' => '2026-01-01', 'to' => '2026-12-31']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ConsolidateGroup::class)->check($this->olle, $this->otherGroup->id, $foreign->id))->toThrow(ModelNotFoundException::class)
        // A run id of another group through one's own group is not found either.
        ->and(fn () => app(ConsolidateGroup::class)->check($this->olle, $this->group->id, $foreign->id))->toThrow(ModelNotFoundException::class);

    $this->actingAs($this->olle);
    $this->get(route('group.consolidation', ['koncern' => $this->otherGroup->id]))->assertNotFound();
    $this->get(route('group.consolidation', ['koncern' => $this->group->id, 'korning' => $foreign->id]))
        ->assertOk()
        ->assertDontSee('Främmande koncernen')
        ->assertDontSee('16 000,00');

    expect(CorporateGroupRun::query()->where('corporate_group_id', $this->otherGroup->id)->count())->toBe(1);
});

it('answers 404 for another group\'s account map, and never writes a map into a company outside the group', function () {
    $foreign = app(MapGroupAccount::class)->save($this->stranger->fresh(), $this->otherGroup->id, $this->r->id, ['account' => '3001', 'group_account' => '3002']);

    expect(fn () => app(MapGroupAccount::class)->remove($this->olle, $this->group->id, $foreign->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(MapGroupAccount::class)->remove($this->olle, $this->otherGroup->id, $foreign->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(MapGroupAccount::class)->save($this->olle, $this->group->id, $this->x->id, ['account' => '3001', 'group_account' => '3002']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(MapGroupAccount::class)->save($this->olle, $this->group->id, $this->r->id, ['account' => '3001', 'group_account' => '3002']))->toThrow(ModelNotFoundException::class)
        ->and(CurrentCompany::run($this->r, fn () => GroupAccountMap::query()->count()))->toBe(1)
        ->and(CurrentCompany::run($this->x, fn () => GroupAccountMap::query()->count()))->toBe(0);

    // Inside company A, R's map row does not exist.
    expect(fn () => CurrentCompany::run($this->a, fn () => GroupAccountMap::query()->findOrFail($foreign->id)))->toThrow(ModelNotFoundException::class);
});

it('never lists another group on the page, nor a map of a company the page does not show', function () {
    app(MapGroupAccount::class)->save($this->stranger->fresh(), $this->otherGroup->id, $this->r->id, ['account' => '3001', 'group_account' => '3009']);
    $this->actingAs($this->olle);

    Livewire::test(ConsolidationPage::class)
        ->assertOk()
        ->assertSee('Koncernen')
        ->assertDontSee('Främmande koncernen')
        ->assertDontSee('3009');
});
