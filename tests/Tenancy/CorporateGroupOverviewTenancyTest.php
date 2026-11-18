<?php

declare(strict_types=1);

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\GrantGroupRole;
use App\Actions\Group\RecordHolding;
use App\Domain\Group\ControlBasis;
use App\Domain\Group\CorporateGroupOverview;
use App\Domain\Group\GroupAccess;
use App\Livewire\Group\CorporateGroupPage;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupMember;
use App\Models\CorporateGroupRole;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * The cross-company reads of Koncern → Översikt (ADR-081 E22, P0): a user sees a group only with
 * reports:view in every member, or with the group role while still a member of the parent; and
 * the structure screen reads no company outside the group and writes nothing on its own.
 *
 * P (parent) holds A; Olle owns P and administers A. B is a company Olle has nothing to do with.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00:00'));
    $this->olle = User::factory()->withCompany()->create(['name' => 'Olle']);
    $this->p = $this->olle->currentCompany();
    $this->p->forceFill(['name' => 'Moder AB'])->save();

    $this->a = e22tCompany('Dotter A AB');
    $this->a->users()->attach($this->olle, ['role' => 'admin']);
    $this->b = e22tCompany('Främmande B AB');

    e22tHolding($this->olle, $this->p, $this->a);
    $this->group = app(CreateCorporateGroup::class)($this->olle->fresh(), $this->p, 'Hemliga koncernen');
    app(ConfirmGroupMember::class)($this->olle->fresh(), $this->group->id, $this->a);
});

function e22tCompany(string $name): Company
{
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $company->forceFill(['name' => $name])->save();
    app(InitialiseBookkeeping::class)($company, $owner, CarbonImmutable::parse('2026-06-15'));

    return $company;
}

function e22tHolding(User $user, Company $holder, Company $held): CompanyHolding
{
    return app(RecordHolding::class)($user->fresh(), $holder, [
        'held_org_number' => $held->org_number, 'held_name' => $held->name, 'capital' => '100', 'voting' => '100',
        'control_basis' => ControlBasis::Votes->value, 'acquired_on' => '2024-01-01',
    ]);
}

it('answers 404 for a stranger, without the group name', function () {
    $stranger = User::factory()->withCompany()->create();

    $this->actingAs($stranger)->get(route('group.corporate', ['koncern' => $this->group->id]))->assertNotFound();

    $this->actingAs($stranger)->get(route('group.corporate'))->assertOk()->assertDontSee('Hemliga koncernen')->assertDontSee('Dotter A AB');

    Livewire::actingAs($stranger)->test(CorporateGroupPage::class)->assertDontSee('Hemliga koncernen')->assertDontSee('Moder AB');
});

it('shows nothing to a partial member who lacks reports:view in one member company', function () {
    $partial = User::factory()->create();
    $this->p->users()->attach($partial, ['role' => 'accountant']);
    // An approver in A has no reports:view there, so the group is invisible although both companies are theirs.
    $this->a->users()->attach($partial, ['role' => 'approver']);

    $this->actingAs($partial->fresh())->get(route('group.corporate', ['koncern' => $this->group->id]))->assertNotFound();
    Livewire::actingAs($partial->fresh())->test(CorporateGroupPage::class)->assertDontSee('Hemliga koncernen')->assertDontSee('Dotter A AB');
});

it('shows nothing to someone who is a member of one company only', function () {
    $onlyA = User::factory()->create();
    $this->a->users()->attach($onlyA, ['role' => 'accountant']);

    $this->actingAs($onlyA->fresh())->get(route('group.corporate', ['koncern' => $this->group->id]))->assertNotFound();
});

it('drops the group at once when the membership or the role in a member company changes', function () {
    $accountant = User::factory()->create();
    $this->p->users()->attach($accountant, ['role' => 'accountant']);
    $this->a->users()->attach($accountant, ['role' => 'accountant']);

    $this->actingAs($accountant->fresh())->get(route('group.corporate', ['koncern' => $this->group->id]))->assertOk();

    $this->a->users()->updateExistingPivot($accountant->id, ['role' => 'approver']);

    $this->actingAs($accountant->fresh())->get(route('group.corporate', ['koncern' => $this->group->id]))->assertNotFound();
});

it('never puts a per-company figure in the payload of a group role holder, and the role ends with the parent membership', function () {
    $viewer = User::factory()->create();
    $this->p->users()->attach($viewer, ['role' => 'approver']);
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);

    $report = app(CorporateGroupOverview::class)->build(GroupAccess::for($viewer->fresh()), $this->group->fresh('members'));
    $json = json_encode($report, JSON_THROW_ON_ERROR);

    expect($report['level']->value)->toBe('CONSOLIDATED')
        ->and($json)->not->toContain($this->a->org_number)->not->toContain('voting_bp')->not->toContain('acquired_on')
        ->not->toContain('locked_until')->not->toContain('year_status')->not->toContain('control_basis');

    // The group role is not a membership in A: no way into A, and the page offers no administration.
    expect(GroupAccess::for($viewer->fresh())->isMember($this->a->id))->toBeFalse();
    Livewire::actingAs($viewer->fresh())->test(CorporateGroupPage::class)->assertDontSee('Spara innehav')->assertDontSee('Ta bort');

    $this->p->users()->detach($viewer);
    $this->actingAs($viewer->fresh())->get(route('group.corporate', ['koncern' => $this->group->id]))->assertNotFound();
});

it('reads no company outside the group: holdings and years of an unrelated company never appear', function () {
    // B is held by A in the books of A but is not a member, and has its own holding of someone else.
    e22tHolding($this->olle, $this->a, $this->b);
    $this->b->forceFill(['locked_until_date' => '2026-01-31'])->save();

    $report = app(CorporateGroupOverview::class)->build(GroupAccess::for($this->olle->fresh()), $this->group->fresh('members'));
    $json = json_encode($report, JSON_THROW_ON_ERROR);

    expect(collect($report['nodes'])->pluck('company_id')->sort()->values()->all())->toBe(collect([$this->p->id, $this->a->id])->sort()->values()->all())
        ->and($json)->not->toContain('Främmande B AB')->not->toContain((string) $this->b->org_number);
});

it('answers 404 when a company outside the user\'s memberships is named by id', function () {
    $page = Livewire::actingAs($this->olle->fresh())->test(CorporateGroupPage::class)
        ->set('holdHolder', (string) $this->b->id)->set('holdHeld', (string) $this->a->id)->set('holdCapital', '100')->set('holdVoting', '100')
        ->set('holdBasis', 'VOTES')->set('holdAcquired', '2024-01-01')->call('saveHolding')
        ->assertDispatched('toast', type: 'error');

    $page->set('addCompany', (string) $this->b->id)->call('addMember')->assertDispatched('toast', type: 'error');

    expect(CompanyScope::bypass(fn () => CompanyHolding::query()->where('company_id', $this->b->id)->count()))->toBe(0)
        ->and(CorporateGroupMember::query()->where('company_id', $this->b->id)->count())->toBe(0);
});

it('refuses a holding in a company where the user lacks company:update and writes nothing there', function () {
    $accountant = User::factory()->create();
    $this->p->users()->attach($accountant, ['role' => 'accountant']);
    $this->a->users()->attach($accountant, ['role' => 'accountant']);
    $before = CompanyScope::bypass(fn () => CompanyHolding::query()->count());

    Livewire::actingAs($accountant->fresh())->test(CorporateGroupPage::class)
        ->set('holdHolder', (string) $this->a->id)->set('holdHeld', (string) $this->p->id)->set('holdCapital', '10')->set('holdVoting', '10')
        ->set('holdBasis', 'VOTES')->set('holdAcquired', '2024-01-01')->call('saveHolding')
        ->assertDispatched('toast', type: 'error');

    expect(CompanyScope::bypass(fn () => CompanyHolding::query()->count()))->toBe($before);
});

it('does not let a non-administrator grant a role or remove a member', function () {
    $accountant = User::factory()->create();
    $this->p->users()->attach($accountant, ['role' => 'accountant']);
    $this->a->users()->attach($accountant, ['role' => 'accountant']);

    $page = Livewire::actingAs($accountant->fresh())->test(CorporateGroupPage::class)
        ->set('group', (string) $this->group->id)
        ->set('roleUser', (string) $accountant->id)->call('grantRole')->assertDispatched('toast', type: 'error')
        ->call('removeMember', $this->a->id)->assertDispatched('toast', type: 'error');

    expect(CorporateGroupRole::query()->count())->toBe(0)->and(CorporateGroupMember::query()->count())->toBe(2);
});

it('writes nothing when the screen is only viewed', function () {
    $audits = AuditLog::query()->count();
    $holdings = CompanyScope::bypass(fn () => CompanyHolding::query()->count());

    Livewire::actingAs($this->olle->fresh())->test(CorporateGroupPage::class)->assertSee('Moder AB');
    $this->actingAs($this->olle->fresh())->get(route('group.corporate'))->assertOk();

    expect(AuditLog::query()->count())->toBe($audits)
        ->and(CompanyScope::bypass(fn () => CompanyHolding::query()->count()))->toBe($holdings)
        ->and(CorporateGroup::query()->count())->toBe(1);
});

it('does not read the holdings of a company outside the group through the current company', function () {
    // The holdings are company data: inside B's own scope the group's holding is invisible.
    expect(CurrentCompany::run($this->b, fn () => CompanyHolding::query()->count()))->toBe(0);
});
