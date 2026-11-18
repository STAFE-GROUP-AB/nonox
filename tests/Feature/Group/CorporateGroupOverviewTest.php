<?php

declare(strict_types=1);

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\GrantGroupRole;
use App\Actions\Group\ManageCompanyGroup;
use App\Actions\Group\RecordHolding;
use App\Domain\Group\ControlBasis;
use App\Domain\Group\CorporateGroupOverview;
use App\Domain\Group\GroupAccess;
use App\Livewire\Group\CorporateGroupPage;
use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupMember;
use App\Models\CorporateGroupRole;
use App\Models\User;
use App\Support\Navigation\Navigation;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Koncern → Översikt, package E22 (ADR-081, ADR-060 S2-1.2): a parent P, a subsidiary A
 * (held 100 %), and C held through A (70 %). Olle owns P and is administrator in A and C.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00:00'));
    $this->olle = User::factory()->withCompany()->create(['name' => 'Olle']);
    $this->p = $this->olle->currentCompany();
    $this->p->forceFill(['name' => 'Moder AB'])->save();
    app(InitialiseBookkeeping::class)($this->p, $this->olle, CarbonImmutable::parse('2026-06-15'));

    foreach (['a' => 'Dotter A AB', 'c' => 'Dotterdotter C AB'] as $key => $name) {
        $company = e22Company($this->olle, $name);
        $this->{$key} = $company;
    }
});

function e22Company(User $admin, string $name): Company
{
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $company->forceFill(['name' => $name])->save();
    $company->users()->attach($admin, ['role' => 'admin']);
    app(InitialiseBookkeeping::class)($company, $owner, CarbonImmutable::parse('2026-06-15'));

    return $company;
}

function e22Holding(User $user, Company $holder, Company $held, string $share = '100', array $extra = []): CompanyHolding
{
    return app(RecordHolding::class)($user->fresh(), $holder, [
        'held_org_number' => $held->org_number,
        'held_name' => $held->name,
        'capital' => $share,
        'voting' => $share,
        'control_basis' => ControlBasis::Votes->value,
        'acquired_on' => '2024-01-01',
        ...$extra,
    ]);
}

/** P holds A (100 %), A holds C (70 %); all three are members. */
function e22Group(object $test): CorporateGroup
{
    e22Holding($test->olle, $test->p, $test->a);
    e22Holding($test->olle, $test->a, $test->c, '70', ['acquired_on' => '2025-03-01']);
    $group = app(CreateCorporateGroup::class)($test->olle->fresh(), $test->p, 'Moderkoncernen');
    app(ConfirmGroupMember::class)($test->olle->fresh(), $group->id, $test->a);
    app(ConfirmGroupMember::class)($test->olle->fresh(), $group->id, $test->c);

    return $group;
}

function e22Viewer(object $test, CorporateGroup $group): User
{
    $viewer = User::factory()->create(['name' => 'Vera Läsare']);
    $test->p->users()->attach($viewer, ['role' => 'approver']);
    app(GrantGroupRole::class)($test->olle->fresh(), $group->id, $viewer->id);

    return $viewer->fresh();
}

it('shows the structure as a tree with holder, capital, votes, dates and period status', function () {
    $group = e22Group($this);
    $this->a->forceFill(['locked_until_date' => '2026-09-30'])->save();

    $report = app(CorporateGroupOverview::class)->build(GroupAccess::for($this->olle->fresh()), $group->fresh('members'));
    $nodes = collect($report['nodes']);

    expect($nodes->pluck('name')->all())->toBe(['Moder AB', 'Dotter A AB', 'Dotterdotter C AB'])
        ->and($nodes->pluck('depth')->all())->toBe([0, 1, 2])
        ->and($nodes->pluck('holder_name')->all())->toBe([null, 'Moder AB', 'Dotter A AB'])
        ->and($nodes->pluck('capital_bp')->all())->toBe([null, 10_000, 7_000])
        ->and($nodes[2]['voting_bp'])->toBe(7_000)
        ->and($nodes[2]['acquired_on']->toDateString())->toBe('2025-03-01')
        ->and($nodes[1]['year'])->toBe('2026')
        ->and($nodes[1]['locked_until']->toDateString())->toBe('2026-09-30');

    Livewire::actingAs($this->olle->fresh())->test(CorporateGroupPage::class)
        ->assertSee('Moder AB')->assertSee('Dotterdotter C AB')->assertSee('70 %')->assertSee('2025-03-01')
        ->assertSee('Öppet')->assertSee('2026-09-30')->assertSee('Ingen låsning');
});

it('shows a disposed holding with its date and keeps the member listed', function () {
    $group = e22Group($this);
    // The holding that makes C a member is disposed of later; the structure still lists it, with the date.
    CurrentCompany::run($this->a, fn () => CompanyHolding::query()->update(['disposed_on' => '2026-02-01']));

    $nodes = collect(app(CorporateGroupOverview::class)->build(GroupAccess::for($this->olle->fresh()), $group->fresh('members'))['nodes']);

    expect($nodes->firstWhere('name', 'Dotterdotter C AB')['disposed_on']->toDateString())->toBe('2026-02-01');
});

it('gives a group role holder the names, the holders and the capital shares and nothing else', function () {
    $group = e22Group($this);
    $viewer = e22Viewer($this, $group);
    $this->a->forceFill(['locked_until_date' => '2026-09-30'])->save();

    $report = app(CorporateGroupOverview::class)->build(GroupAccess::for($viewer), $group->fresh('members'));

    expect($report['level']->value)->toBe('CONSOLIDATED')
        ->and($report['roles'])->toBe([])->and($report['candidates'])->toBe([])->and($report['administers'])->toBeFalse();

    foreach ($report['nodes'] as $node) {
        expect(array_keys($node))->toEqualCanonicalizing(['company_id', 'name', 'holder_id', 'holder_name', 'is_parent', 'capital_bp', 'no_control', 'depth']);
    }

    Livewire::actingAs($viewer)->test(CorporateGroupPage::class)
        ->assertSee('Dotter A AB')->assertSee('70 %')
        ->assertDontSee($this->a->org_number)->assertDontSee('2025-03-01')->assertDontSee('2026-09-30')
        ->assertDontSee('Låst till')->assertDontSee('Röster')->assertDontSee('Registrera innehav')->assertDontSee('Vem får läsa sammanställningen');
});

it('creates a group, records a holding, adds the member and removes it again from the screen', function () {
    $page = Livewire::actingAs($this->olle->fresh())->test(CorporateGroupPage::class)
        ->set('newName', 'Min koncern')->set('newParent', (string) $this->p->id)->call('create')
        ->assertDispatched('toast', message: 'Koncernen är skapad. Registrera innehaven i dotterföretagen och lägg till dem.', type: 'success');

    $group = CorporateGroup::query()->sole();
    $page->assertSet('group', (string) $group->id);

    $page->set('holdHolder', (string) $this->p->id)->set('holdHeld', (string) $this->a->id)->set('holdCapital', '100')->set('holdVoting', '100')
        ->set('holdBasis', 'VOTES')->set('holdAcquired', '2024-01-01')->call('saveHolding')
        ->assertDispatched('toast', message: 'Innehavet är sparat.', type: 'success');

    expect(CurrentCompany::run($this->p, fn () => CompanyHolding::query()->count()))->toBe(1);

    // Saving again for the same company changes the holding instead of adding a second one.
    $page->set('holdHolder', (string) $this->p->id)->set('holdHeld', (string) $this->a->id)->set('holdCapital', '90')->set('holdVoting', '90')
        ->set('holdBasis', 'VOTES')->set('holdAcquired', '2024-01-01')->call('saveHolding');

    expect(CurrentCompany::run($this->p, fn () => CompanyHolding::query()->pluck('capital_bp')->all()))->toBe([9_000]);

    $page->set('addCompany', (string) $this->a->id)->call('addMember')
        ->assertDispatched('toast', message: 'Företaget ingår nu i koncernen.', type: 'success');
    expect(CorporateGroupMember::query()->count())->toBe(2);

    $page->call('removeMember', $this->a->id)
        ->assertDispatched('toast', message: 'Företaget ingår inte längre i koncernen.', type: 'success');
    expect(CorporateGroupMember::query()->count())->toBe(1);
});

it('refuses a member without a holding with a toast and changes nothing', function () {
    $group = app(CreateCorporateGroup::class)($this->olle->fresh(), $this->p, 'Koncernen');

    Livewire::actingAs($this->olle->fresh())->test(CorporateGroupPage::class)
        ->set('group', (string) $group->id)->set('addCompany', (string) $this->a->id)->call('addMember')
        ->assertDispatched('toast', type: 'error');

    expect(CorporateGroupMember::query()->count())->toBe(1);
});

it('grants and revokes the viewer role from the screen', function () {
    $group = e22Group($this);
    $person = User::factory()->create(['name' => 'Petra Person']);
    $this->p->users()->attach($person, ['role' => 'accountant']);

    $page = Livewire::actingAs($this->olle->fresh())->test(CorporateGroupPage::class)
        ->assertSee('Petra Person')
        ->set('roleUser', (string) $person->id)->call('grantRole')
        ->assertDispatched('toast', message: 'Personen kan nu läsa koncernens sammanställning.', type: 'success');

    expect(CorporateGroupRole::query()->where('user_id', $person->id)->count())->toBe(1);

    $page->call('revokeRole', $person->id)->assertDispatched('toast', message: 'Rollen är borttagen.', type: 'success');

    expect(CorporateGroupRole::query()->count())->toBe(0);
});

it('converts a personal list into a shared group with the chosen parent', function () {
    $list = app(ManageCompanyGroup::class)->create($this->olle->fresh(), 'Min lista');

    foreach ([$this->p, $this->a] as $company) {
        app(ManageCompanyGroup::class)->addCompany($this->olle->fresh(), $list->id, $company->id, null);
    }

    Livewire::actingAs($this->olle->fresh())->test(CorporateGroupPage::class)
        ->assertSee('Gör om en tidigare lista till koncern')
        ->set('convertList', (string) $list->id)->set('convertParent', (string) $this->p->id)->call('convert')
        ->assertDispatched('toast', type: 'success');

    $group = CorporateGroup::query()->sole();

    expect($group->name)->toBe('Min lista')->and($group->parent_company_id)->toBe($this->p->id)
        ->and($group->members()->count())->toBe(1)
        ->and(DB::table('company_groups')->where('id', $list->id)->exists())->toBeTrue();
});

it('refuses to convert with a parent outside the list, and another person\'s list answers 404', function () {
    $list = app(ManageCompanyGroup::class)->create($this->olle->fresh(), 'Min lista');
    app(ManageCompanyGroup::class)->addCompany($this->olle->fresh(), $list->id, $this->p->id, null);

    Livewire::actingAs($this->olle->fresh())->test(CorporateGroupPage::class)
        ->set('convertList', (string) $list->id)->set('convertParent', (string) $this->a->id)->call('convert')
        ->assertDispatched('toast', type: 'error');

    expect(CorporateGroup::query()->count())->toBe(0);

    $other = User::factory()->withCompany()->create();
    Livewire::actingAs($other)->test(CorporateGroupPage::class)
        ->set('convertList', (string) $list->id)->set('convertParent', (string) $other->currentCompany()->id)->call('convert')
        ->assertDispatched('toast', type: 'error');

    expect(CorporateGroup::query()->count())->toBe(0);
});

it('runs a fixed number of queries whether the group has two or six members', function () {
    $group = e22Group($this);
    $count = function () use ($group): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->olle->fresh())->test(CorporateGroupPage::class)->set('group', (string) $group->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $short = $count();

    foreach (['D', 'E', 'F'] as $letter) {
        $company = e22Company($this->olle, "Dotter {$letter} AB");
        e22Holding($this->olle, $this->p, $company);
        app(ConfirmGroupMember::class)($this->olle->fresh(), $group->id, $company);
    }

    expect($group->members()->count())->toBe(6)->and($short)->toBeGreaterThan(5)->and($count())->toBe($short);
});

it('shows Koncernstruktur in the menu and the search for a group role holder who is in the parent only', function () {
    $group = e22Group($this);
    $viewer = e22Viewer($this, $group);

    expect($viewer->companies()->count())->toBe(1);

    $routes = collect(app(Navigation::class)->pages($viewer, $this->p))->pluck('route')->all();

    expect($routes)->toContain('group.corporate')->not->toContain('group.overview');

    // A person with one company and no group sees no such entry.
    $other = User::factory()->withCompany()->create();

    expect(collect(app(Navigation::class)->pages($other, $other->currentCompany()))->pluck('route')->all())->not->toContain('group.corporate');
});

it('marks a holding without controlling influence for every access level, without showing the basis to a role holder', function () {
    $group = e22Group($this);
    CurrentCompany::run($this->a, fn () => CompanyHolding::query()->update(['control_basis' => ControlBasis::None->value]));
    $viewer = e22Viewer($this, $group);

    $full = collect(app(CorporateGroupOverview::class)->build(GroupAccess::for($this->olle->fresh()), $group->fresh('members'))['nodes'])->firstWhere('name', 'Dotterdotter C AB');
    $limited = collect(app(CorporateGroupOverview::class)->build(GroupAccess::for($viewer), $group->fresh('members'))['nodes'])->firstWhere('name', 'Dotterdotter C AB');

    expect($full['no_control'])->toBeTrue()->and($limited['no_control'])->toBeTrue()->and($limited)->not->toHaveKey('control_basis');

    Livewire::actingAs($viewer)->test(CorporateGroupPage::class)->assertSee('Inte dotterföretag genom detta innehav')->assertDontSee('Inget bestämmande inflytande');
    Livewire::actingAs($this->olle->fresh())->test(CorporateGroupPage::class)
        ->assertSee('NONOX prövar inte om företagen utgör en koncern')->assertSee('wire:confirm', false);
});
