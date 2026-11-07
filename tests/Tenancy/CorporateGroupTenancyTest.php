<?php

declare(strict_types=1);

use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\GrantGroupRole;
use App\Actions\Group\RecordHolding;
use App\Actions\Group\RemoveGroupMember;
use App\Actions\Group\RevokeGroupRole;
use App\Domain\Firm\Memberships;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupAccessLevel;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupRole;
use App\Models\User;
use App\Modules\Connect\Actions\CreateApiKey;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for shared groups (ADR-060, addendum 2026-11-07, 2.2). The
 * parent P holds A and B; Olle owns P and administers A and B. Access is all
 * or nothing: reports:view in every member (FULL), or a viewer role while
 * still a member of P (CONSOLIDATED), or nothing at all (NONE, 404 without
 * the group's name). A group role never reaches a single company.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00:00'));
    [$this->olle, $this->p] = Bookkeeping::company();
    $this->p->forceFill(['name' => 'Moder AB'])->save();

    foreach (['a' => 'Dotter A AB', 'b' => 'Hemliga Dotter B AB'] as $key => $name) {
        [$owner, $company] = Bookkeeping::company();
        $company->forceFill(['name' => $name])->save();
        $company->users()->attach($this->olle, ['role' => 'admin']);
        $this->{$key} = $company;
        $this->{"{$key}Owner"} = $owner;

        app(RecordHolding::class)($this->olle->fresh(), $this->p, [
            'held_org_number' => $company->org_number, 'held_name' => $name, 'capital' => '100', 'voting' => '100',
            'control_basis' => 'VOTES', 'acquired_on' => '2024-01-01',
        ]);
    }

    $this->group = app(CreateCorporateGroup::class)($this->olle->fresh(), $this->p, 'Den hemliga koncernen');
    app(ConfirmGroupMember::class)($this->olle->fresh(), $this->group->id, $this->a);
    app(ConfirmGroupMember::class)($this->olle->fresh(), $this->group->id, $this->b);
    $this->secretVoucher = CurrentCompany::run($this->b, fn () => Bookkeeping::post($this->bOwner, $this->b));
});

/** Every way a user can reach a group by id; each must answer 404 for someone at NONE. */
function cgtAttempts(User $user, CorporateGroup $group, Company $company): array
{
    return [
        'resolve' => fn () => GroupAccess::for($user->fresh())->corporateGroup($group->id),
        'confirm' => fn () => app(ConfirmGroupMember::class)($user->fresh(), $group->id, $company),
        'remove' => fn () => app(RemoveGroupMember::class)($user->fresh(), $group->id, $company->id),
        'grant' => fn () => app(GrantGroupRole::class)($user->fresh(), $group->id, $user->id),
        // Someone else's role: giving up one's own works even at NONE (tested below).
        'revoke' => fn () => app(RevokeGroupRole::class)($user->fresh(), $group->id, $user->id + 100_000),
    ];
}

function cgtAssertNothing(User $user, CorporateGroup $group, Company $company): void
{
    $access = GroupAccess::for($user->fresh());

    expect($access->levelFor($group))->toBe(GroupAccessLevel::None)
        ->and($access->corporateGroups()->pluck('id')->all())->not->toContain($group->id);

    $missing = (int) CorporateGroup::query()->max('id') + 1000;

    foreach ([$group->id, $missing] as $id) {
        $target = $id === $group->id ? $group : (new CorporateGroup)->forceFill(['id' => $id]);

        foreach (cgtAttempts($user, $target, $company) as $name => $attempt) {
            try {
                $attempt();
                test()->fail("{$name} did not answer 404");
            } catch (ModelNotFoundException $e) {
                // Another tenant's group and one that does not exist look the same, and the name is never told.
                expect($e->getMessage())->not->toContain('hemliga')->and($e->getMessage())->not->toContain('Hemliga');
            }
        }
    }
}

it('shows the whole group to someone with reports:view in every member', function () {
    $user = User::factory()->create();

    foreach ([$this->p, $this->a, $this->b] as $company) {
        $company->users()->attach($user, ['role' => 'accountant']);
    }

    $access = GroupAccess::for($user->fresh());

    expect($access->levelFor($this->group))->toBe(GroupAccessLevel::Full)
        ->and($access->corporateGroups()->pluck('name')->all())->toBe(['Den hemliga koncernen'])
        ->and($access->administers($this->group))->toBeFalse();
});

it('shows nothing at group level to a partial member', function () {
    // reports:view in P and A, not a member of B.
    $user = User::factory()->create();
    $this->p->users()->attach($user, ['role' => 'accountant']);
    $this->a->users()->attach($user, ['role' => 'accountant']);

    cgtAssertNothing($user, $this->group, $this->a);
});

it('shows nothing to a member of every company whose role in one lacks reports:view', function () {
    $user = User::factory()->create();
    $this->p->users()->attach($user, ['role' => 'accountant']);
    $this->a->users()->attach($user, ['role' => 'accountant']);
    $this->b->users()->attach($user, ['role' => 'approver']);

    cgtAssertNothing($user, $this->group, $this->a);
});

it('drops full access at once when a membership is removed or loses reports:view', function () {
    $user = User::factory()->create();

    foreach ([$this->p, $this->a, $this->b] as $company) {
        $company->users()->attach($user, ['role' => 'accountant']);
    }

    expect(GroupAccess::for($user->fresh())->levelFor($this->group))->toBe(GroupAccessLevel::Full);

    $this->b->users()->updateExistingPivot($user->id, ['role' => 'approver']);
    cgtAssertNothing($user, $this->group, $this->a);

    $this->b->users()->updateExistingPivot($user->id, ['role' => 'accountant']);
    expect(GroupAccess::for($user->fresh())->levelFor($this->group))->toBe(GroupAccessLevel::Full);

    $this->a->users()->detach($user);
    cgtAssertNothing($user, $this->group, $this->b);
});

it('drops full access at once when a company leaves the group, and shows the rest again', function () {
    $user = User::factory()->create();
    $this->p->users()->attach($user, ['role' => 'accountant']);
    $this->a->users()->attach($user, ['role' => 'accountant']);

    expect(GroupAccess::for($user->fresh())->levelFor($this->group))->toBe(GroupAccessLevel::None);

    app(RemoveGroupMember::class)($this->bOwner->fresh(), $this->group->id, $this->b->id);

    expect(GroupAccess::for($user->fresh())->levelFor($this->group))->toBe(GroupAccessLevel::Full);
});

it('never lets a group role reach a single company\'s ledger', function () {
    $viewer = Bookkeeping::member($this->p, 'approver');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);
    $viewer = $viewer->fresh();

    expect(GroupAccess::for($viewer)->levelFor($this->group))->toBe(GroupAccessLevel::Consolidated)
        ->and(GroupAccess::for($viewer)->corporateGroups()->pluck('id')->all())->toBe([$this->group->id]);

    // Not a membership: B is in no list of the viewer's companies and grants no permission.
    expect(Memberships::of($viewer)->has($this->b->id))->toBeFalse()
        ->and($viewer->companies()->pluck('id')->all())->toBe([$this->p->id])
        ->and($viewer->belongsToTeam($this->b))->toBeFalse()
        ->and($viewer->hasTeamPermission($this->b, 'reports:view'))->toBeFalse()
        ->and($viewer->hasTeamPermission($this->b, 'vouchers:view'))->toBeFalse()
        ->and($viewer->switchCompany($this->b))->toBeFalse()
        ->and($viewer->fresh()->current_team_id)->toBe($this->p->id);

    // B's records answer 404 as for any foreign company.
    $this->actingAs($viewer->fresh());
    $this->get(route('accounting.vouchers.show', $this->secretVoucher->id))->assertNotFound();
    $this->get(route('accounting.vouchers.edit', $this->secretVoucher->id))->assertNotFound();

    // No API credential can be bound to B.
    expect(fn () => app(CreateApiKey::class)($viewer->fresh(), $this->b, ['name' => 'Kapad', 'scopes' => ['vouchers:read'], 'expires_on' => '2026-12-31']))
        ->toThrow(AuthorizationException::class);

    // And it administers nothing.
    expect(GroupAccess::for($viewer->fresh())->administers($this->group))->toBeFalse()
        ->and(fn () => app(ConfirmGroupMember::class)($viewer->fresh(), $this->group->id, $this->p))->toThrow(AuthorizationException::class)
        ->and(fn () => app(GrantGroupRole::class)($viewer->fresh(), $this->group->id, $viewer->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveGroupMember::class)($viewer->fresh(), $this->group->id, $this->b->id))->toThrow(AuthorizationException::class);
});

it('removes the consolidated view at once when the viewer leaves the parent', function () {
    $viewer = Bookkeeping::member($this->p, 'approver');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);

    expect(GroupAccess::for($viewer->fresh())->levelFor($this->group))->toBe(GroupAccessLevel::Consolidated);

    $this->p->users()->detach($viewer);

    cgtAssertNothing($viewer, $this->group, $this->a);
});

it('keeps another company\'s holdings out of reach', function () {
    $foreign = CurrentCompany::run($this->p, fn () => CompanyHolding::query()->where('held_org_number', $this->a->org_number)->sole());

    // The owner of A administers A, not P: P's holding id is unknown inside A.
    expect(fn () => app(RecordHolding::class)($this->aOwner->fresh(), $this->a, [
        'held_org_number' => $this->b->org_number, 'held_name' => 'X', 'capital' => '1', 'voting' => '1', 'control_basis' => 'NONE', 'acquired_on' => '2024-01-01',
    ], $foreign->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RecordHolding::class)($this->aOwner->fresh(), $this->p, ['held_name' => 'X']))->toThrow(AuthorizationException::class)
        ->and(CurrentCompany::run($this->a, fn () => CompanyHolding::query()->count()))->toBe(0)
        ->and($foreign->fresh()?->held_name)->toBe('Dotter A AB');
});

it('names the held company by organisation number, never by another tenant\'s id', function () {
    expect(Schema::getColumnListing('company_holdings'))->toEqualCanonicalizing([
        'id', 'company_id', 'held_org_number', 'held_name', 'capital_bp', 'voting_bp', 'control_basis',
        'acquired_on', 'disposed_on', 'common_control', 'created_at', 'updated_at',
    ]);

    $foreignKeys = collect(Schema::getForeignKeys('company_holdings'))->map(fn (array $key): array => [$key['columns'], $key['foreign_table']])->all();

    expect($foreignKeys)->toBe([[['company_id'], 'teams']]);
});

/** P's holding that grounds B's membership. */
function cgtHoldingInB(object $test): CompanyHolding
{
    return CurrentCompany::run($test->p, fn () => CompanyHolding::query()->where('held_org_number', $test->b->org_number)->sole());
}

/** @param array<string, string> $extra */
function cgtHoldingInput(Company $held, array $extra = []): array
{
    return [
        'held_org_number' => $held->org_number, 'held_name' => $held->name, 'capital' => '100', 'voting' => '100',
        'control_basis' => 'VOTES', 'acquired_on' => '2024-01-01', ...$extra,
    ];
}

it('lets an administrator of the holder alone neither back-date, re-point nor change a holding that grounds a membership', function () {
    // Pia administers P only. Editing P's holding in B would take B out of the group without B's consent.
    $pia = Bookkeeping::member($this->p, 'admin');
    $holding = cgtHoldingInB($this);
    $partial = User::factory()->create();
    $this->p->users()->attach($partial, ['role' => 'accountant']);
    $this->a->users()->attach($partial, ['role' => 'accountant']);

    foreach ([
        'back-date' => ['disposed_on' => '2025-01-01'],
        're-point' => ['held_org_number' => $this->a->org_number],
        'votes' => ['voting' => '40'],
    ] as $change) {
        expect(fn () => app(RecordHolding::class)($pia->fresh(), $this->p, cgtHoldingInput($this->b, $change), $holding->id))
            ->toThrow(AuthorizationException::class);
    }

    $fresh = $holding->fresh();

    expect($fresh?->held_org_number)->toBe($this->b->org_number)
        ->and($fresh?->voting_bp)->toBe(10000)
        ->and($fresh?->disposed_on)->toBeNull()
        ->and(GroupAccess::for($partial->fresh())->levelFor($this->group, CarbonImmutable::parse('2026-01-01')))->toBe(GroupAccessLevel::None);
});

it('freezes the organisation number of a grounding holding even for whoever administers the group', function () {
    expect(fn () => app(RecordHolding::class)($this->olle->fresh(), $this->p, cgtHoldingInput($this->b, ['held_org_number' => '556036-0793']), cgtHoldingInB($this)->id))
        ->toThrow(ValidationException::class, 'Organisationsnumret kan inte ändras när innehavet ligger till grund för en koncern.');

    // Whoever administers every member may record the disposal.
    $disposed = app(RecordHolding::class)($this->olle->fresh(), $this->p, cgtHoldingInput($this->b, ['disposed_on' => '2025-12-31']), cgtHoldingInB($this)->id);

    expect($disposed->disposed_on?->toDateString())->toBe('2025-12-31');
});

it('exposes one inclusion rule for which members a period needs', function () {
    app(RecordHolding::class)($this->olle->fresh(), $this->p, cgtHoldingInput($this->b, ['disposed_on' => '2025-12-31']), cgtHoldingInB($this)->id);
    $access = GroupAccess::for($this->olle->fresh());

    expect($access->includedCompanyIds($this->group))->toEqualCanonicalizing([$this->p->id, $this->a->id, $this->b->id])
        ->and($access->includedCompanyIds($this->group, CarbonImmutable::parse('2025-06-01')))->toEqualCanonicalizing([$this->p->id, $this->a->id, $this->b->id])
        ->and($access->includedCompanyIds($this->group, CarbonImmutable::parse('2026-01-01')))->toEqualCanonicalizing([$this->p->id, $this->a->id]);
});

it('does not revive a viewer role when its holder leaves the parent and is invited again', function () {
    $viewer = Bookkeeping::member($this->p, 'approver');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);
    $this->p->users()->detach($viewer);
    $this->travel(1)->minutes();
    $this->p->users()->attach($viewer, ['role' => 'approver']);

    cgtAssertNothing($viewer, $this->group, $this->a);

    // A new grant, for the new membership, is needed and is logged.
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);

    expect(GroupAccess::for($viewer->fresh())->levelFor($this->group))->toBe(GroupAccessLevel::Consolidated)
        ->and(CorporateGroupRole::query()->where('user_id', $viewer->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'corporate_group.role_granted')->count())->toBe(6);
});

it('does not revive a viewer role even when the new membership starts in the same second', function () {
    $viewer = Bookkeeping::member($this->p, 'approver');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);
    $this->p->users()->detach($viewer);
    $this->p->users()->attach($viewer, ['role' => 'approver']);

    expect(GroupAccess::for($viewer->fresh())->levelFor($this->group))->toBe(GroupAccessLevel::None);
});

it('lets a former member of the parent give up their own role without seeing the group', function () {
    $viewer = Bookkeeping::member($this->p, 'approver');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);
    $this->p->users()->detach($viewer);

    app(RevokeGroupRole::class)($viewer->fresh(), $this->group->id, $viewer->id);

    expect(CorporateGroupRole::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'corporate_group.role_revoked')->count())->toBe(3)
        ->and(fn () => app(RevokeGroupRole::class)($viewer->fresh(), $this->group->id, $viewer->id))->toThrow(ModelNotFoundException::class);
});

it('keeps one active holding per holder and held company in the database, and blocks deleting a grounding holding', function () {
    // Each attempt in its own savepoint, so the failed statement does not abort the test's transaction.
    expect(fn () => DB::transaction(fn () => CurrentCompany::run($this->p, fn () => CompanyHolding::create([
        'held_org_number' => $this->a->org_number, 'held_name' => 'Dubblett', 'capital_bp' => 1, 'voting_bp' => 1,
        'control_basis' => 'NONE', 'acquired_on' => '2025-01-01',
    ]))))->toThrow(QueryException::class);

    $holdingFk = collect(Schema::getForeignKeys('corporate_group_members'))->firstWhere('columns', ['holding_id']);

    expect($holdingFk['on_delete'] ?? null)->toBe('no action')
        ->and(fn () => DB::transaction(fn () => CurrentCompany::run($this->p, fn () => cgtHoldingInB($this)->delete())))->toThrow(QueryException::class)
        ->and(CurrentCompany::run($this->p, fn () => CompanyHolding::query()->count()))->toBe(2);
});

it('gives an administrator of the holder alone the same answer whatever other members hold (no oracle)', function () {
    // Y is an outside company. Pia administers P only; B's holding in Y is not hers to learn.
    $y = ['held_org_number' => '556036-0793', 'held_name' => 'Utomstående AB', 'capital' => '50', 'voting' => '50', 'control_basis' => 'NONE', 'acquired_on' => '2024-01-01'];
    $pia = Bookkeeping::member($this->p, 'admin');

    app(RecordHolding::class)($this->olle->fresh(), $this->b, [...$y, 'voting' => '60']);
    $alone = app(RecordHolding::class)($pia->fresh(), $this->p, $y);

    // With B at 60 % Pia can still raise P's share to anything up to 100 %: no refusal reveals B's share.
    foreach (['51', '90', '100'] as $voting) {
        expect(app(RecordHolding::class)($pia->fresh(), $this->p, [...$y, 'voting' => $voting], $alone->id)->voting_bp)->toBe((int) $voting * 100);
    }

    // Whoever administers the group still gets the group's rule, and the conflict is the group's to resolve.
    expect(fn () => app(RecordHolding::class)($this->olle->fresh(), $this->p, [...$y, 'voting' => '41'], $alone->id))
        ->toThrow(ValidationException::class, 'mer än 100 procent av rösterna');
});
