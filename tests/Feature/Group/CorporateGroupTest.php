<?php

declare(strict_types=1);

use App\Actions\Company\TransferOwnership;
use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\GrantGroupRole;
use App\Actions\Group\RecordHolding;
use App\Actions\Group\RemoveGroupMember;
use App\Actions\Group\RevokeGroupRole;
use App\Domain\Group\ControlBasis;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupAccessLevel;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupMember;
use App\Models\CorporateGroupRole;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * Shared groups, package S2-1.1 (ADR-060, addendum 2026-11-07): a parent P
 * holding A and B, and C held through A. Olle owns P and is administrator in
 * A, B and C. Holdings live in the holder's books; members are confirmed by
 * someone who administers every company involved.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00:00'));
    $this->olle = User::factory()->withCompany()->create(['name' => 'Olle']);
    $this->p = $this->olle->currentCompany();
    $this->p->forceFill(['name' => 'Moder AB'])->save();

    foreach (['a' => 'Dotter A AB', 'b' => 'Dotter B AB', 'c' => 'Dotterdotter C AB'] as $key => $name) {
        $owner = User::factory()->withCompany()->create();
        $company = $owner->currentCompany();
        $company->forceFill(['name' => $name])->save();
        $company->users()->attach($this->olle, ['role' => 'admin']);
        $this->{$key} = $company;
        $this->{"{$key}Owner"} = $owner;
    }
});

function cgHolding(User $user, Company $holder, Company $held, string $voting = '100', array $extra = []): CompanyHolding
{
    return app(RecordHolding::class)($user->fresh(), $holder, [
        'held_org_number' => $held->org_number,
        'held_name' => $held->name,
        'capital' => $voting,
        'voting' => $voting,
        'control_basis' => ControlBasis::Votes->value,
        'acquired_on' => '2024-01-01',
        ...$extra,
    ]);
}

function cgGroup(object $test): CorporateGroup
{
    return app(CreateCorporateGroup::class)($test->olle->fresh(), $test->p, 'Moderkoncernen');
}

function cgConfirm(User $user, CorporateGroup $group, Company $company): CorporateGroupMember
{
    return app(ConfirmGroupMember::class)($user->fresh(), $group->id, $company);
}

/** @return list<string> the payloads of the group's entries in the log, as JSON */
function cgPayloads(CorporateGroup $group): array
{
    return AuditLog::query()->where('subject_type', $group->getMorphClass())->where('subject_id', (string) $group->id)
        ->get()->map(fn (AuditLog $log): string => json_encode($log->payload, JSON_THROW_ON_ERROR))->all();
}

it('creates a group with the parent as its first member and logs it in the parent only', function () {
    $group = cgGroup($this);

    expect($group->parent_company_id)->toBe($this->p->id)
        ->and($group->members()->sole()->only(['company_id', 'holding_id', 'confirmed_by']))->toBe(['company_id' => $this->p->id, 'holding_id' => null, 'confirmed_by' => $this->olle->id])
        ->and(GroupAccess::for($this->olle->fresh())->levelFor($group))->toBe(GroupAccessLevel::Full);

    $log = AuditLog::query()->where('action', 'corporate_group.created')->sole();

    expect($log->company_id)->toBe($this->p->id)
        ->and($log->payload)->toEqual(['corporate_group_id' => $group->id, 'members' => 1, 'this_company' => true]);
});

it('allows one group per parent and a name of at most 100 characters', function () {
    cgGroup($this);

    expect(fn () => cgGroup($this))->toThrow(ValidationException::class, 'Företaget är redan moderföretag i en koncern.')
        ->and(fn () => app(CreateCorporateGroup::class)($this->olle->fresh(), $this->a, str_repeat('x', 101)))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateCorporateGroup::class)($this->olle->fresh(), $this->a, '  '))->toThrow(ValidationException::class);
});

it('lets only an owner or administrator create a group', function (string $role) {
    $user = User::factory()->create();
    $this->p->users()->attach($user, ['role' => $role]);

    expect(fn () => app(CreateCorporateGroup::class)($user->fresh(), $this->p, 'Koncernen'))->toThrow(AuthorizationException::class)
        ->and(CorporateGroup::query()->count())->toBe(0);
})->with(['accountant', 'bookkeeper', 'approver', 'auditor']);

it('answers 404 when creating a group for a company the user is not a member of', function () {
    expect(fn () => app(CreateCorporateGroup::class)($this->aOwner->fresh(), $this->p, 'Kapad'))->toThrow(ModelNotFoundException::class);
});

it('confirms direct and indirect subsidiaries from the holdings of members', function () {
    $direct = cgHolding($this->olle, $this->p, $this->a);
    cgHolding($this->olle, $this->a, $this->c, '70');
    $group = cgGroup($this);

    // C is held through A, so it can only join once A is a member.
    expect(fn () => cgConfirm($this->olle, $group, $this->c))->toThrow(ValidationException::class, 'Inget företag i koncernen har ett registrerat innehav i Dotterdotter C AB.');

    $memberA = cgConfirm($this->olle, $group, $this->a);
    $memberC = cgConfirm($this->olle, $group, $this->c);

    expect($memberA->holding_id)->toBe($direct->id)
        ->and($memberC->holding_id)->toBe(CurrentCompany::run($this->a, fn () => CompanyHolding::query()->sole()->id))
        ->and($group->members()->count())->toBe(3)
        ->and(fn () => cgConfirm($this->olle, $group, $this->a))->toThrow(ValidationException::class, 'Företaget ingår redan i koncernen.')
        ->and(fn () => cgConfirm($this->olle, $group, $this->p))->toThrow(ValidationException::class, 'Företaget ingår redan i koncernen.');
});

it('refuses a company without a holding in the group, or with a disposed one', function () {
    $group = cgGroup($this);

    expect(fn () => cgConfirm($this->olle, $group, $this->a))->toThrow(ValidationException::class, 'Registrera innehavet hos ägaren först.');

    cgHolding($this->olle, $this->p, $this->a, '100', ['acquired_on' => '2020-01-01', 'disposed_on' => '2025-06-30']);

    expect(fn () => cgConfirm($this->olle, $group, $this->a))->toThrow(ValidationException::class, 'Registrera innehavet hos ägaren först.')
        ->and($group->members()->count())->toBe(1);
});

it('refuses a company that reports in another currency than the parent (S2-R10)', function () {
    cgHolding($this->olle, $this->p, $this->a);
    $this->a->forceFill(['base_currency' => 'EUR'])->save();
    $group = cgGroup($this);

    expect(fn () => cgConfirm($this->olle, $group, $this->a))->toThrow(ValidationException::class, 'Företaget redovisar i EUR och moderföretaget i SEK. Koncerner med företag i olika valutor stöds inte.')
        ->and($group->members()->count())->toBe(1);
});

it('refuses a company whose votes within the group add up to more than 100 percent', function () {
    // Recorded before P and B form a group, so no group's vote check ran yet.
    cgHolding($this->olle, $this->p, $this->b);
    cgHolding($this->olle, $this->p, $this->a, '60');
    cgHolding($this->olle, $this->b, $this->a, '50');
    $group = cgGroup($this);
    cgConfirm($this->olle, $group, $this->b);

    expect(fn () => cgConfirm($this->olle, $group, $this->a))->toThrow(ValidationException::class, 'Innehaven i Dotter A AB har tillsammans mer än 100 procent av rösterna.')
        ->and($group->members()->pluck('company_id')->all())->not->toContain($this->a->id);

    // Exactly 100 % is accepted, through the holding with the larger share.
    $holding = CurrentCompany::run($this->b, fn () => CompanyHolding::query()->sole());
    app(RecordHolding::class)($this->olle->fresh(), $this->b, [...cgHoldingInput($this->a, '40')], $holding->id);

    expect(cgConfirm($this->olle, $group, $this->a)->holding_id)->toBe(CurrentCompany::run($this->p, fn () => CompanyHolding::query()->where('held_org_number', $this->a->org_number)->sole()->id));
});

function cgHoldingInput(Company $held, string $voting): array
{
    return ['held_org_number' => $held->org_number, 'held_name' => $held->name, 'capital' => $voting, 'voting' => $voting, 'control_basis' => 'VOTES', 'acquired_on' => '2024-01-01'];
}

it('needs an owner or administrator in the new company and in every company already in the group', function () {
    cgHolding($this->olle, $this->p, $this->a);
    cgHolding($this->olle, $this->p, $this->b);
    $group = cgGroup($this);
    cgConfirm($this->olle, $group, $this->a);

    // Accountant in the new company B.
    $this->b->users()->updateExistingPivot($this->olle->id, ['role' => 'accountant']);
    expect(fn () => cgConfirm($this->olle, $group, $this->b))->toThrow(AuthorizationException::class);

    // Administrator of B, but only accountant in the member A.
    $this->b->users()->updateExistingPivot($this->olle->id, ['role' => 'admin']);
    $this->a->users()->updateExistingPivot($this->olle->id, ['role' => 'accountant']);
    expect(fn () => cgConfirm($this->olle, $group, $this->b))->toThrow(AuthorizationException::class);

    // B's own owner does not administer P and A.
    expect(fn () => cgConfirm($this->bOwner, $group, $this->b))->toThrow(ModelNotFoundException::class)
        ->and($group->members()->count())->toBe(2);
});

it('writes one log entry per affected company and never names another member company', function () {
    cgHolding($this->olle, $this->p, $this->a);
    cgHolding($this->olle, $this->p, $this->b);
    $group = cgGroup($this);
    cgConfirm($this->olle, $group, $this->a);
    cgConfirm($this->olle, $group, $this->b);

    $confirmed = AuditLog::query()->where('action', 'corporate_group.member_confirmed')->orderBy('id')->get();

    // A's confirmation: P and A; B's: P, A and B. Each company has its own entry, with its own id.
    expect($confirmed->pluck('company_id')->all())->toBe([$this->p->id, $this->a->id, $this->p->id, $this->a->id, $this->b->id])
        ->and($confirmed->last()->payload)->toEqual(['corporate_group_id' => $group->id, 'members' => 3, 'this_company' => true])
        ->and($confirmed->get(2)->payload)->toEqual(['corporate_group_id' => $group->id, 'members' => 3, 'this_company' => false]);

    $viewer = User::factory()->create();
    $this->p->users()->attach($viewer, ['role' => 'approver']);
    app(GrantGroupRole::class)($this->olle->fresh(), $group->id, $viewer->id);
    app(RevokeGroupRole::class)($this->olle->fresh(), $group->id, $viewer->id);
    app(RemoveGroupMember::class)($this->olle->fresh(), $group->id, $this->b->id);

    foreach (['corporate_group.role_granted', 'corporate_group.role_revoked'] as $action) {
        expect(AuditLog::query()->where('action', $action)->orderBy('company_id')->pluck('company_id')->all())->toBe(collect([$this->p->id, $this->a->id, $this->b->id])->sort()->values()->all());
    }

    expect(AuditLog::query()->where('action', 'corporate_group.member_removed')->orderBy('company_id')->pluck('company_id')->all())
        ->toBe(collect([$this->p->id, $this->a->id, $this->b->id])->sort()->values()->all());

    foreach (AuditLog::query()->where('subject_type', $group->getMorphClass())->get() as $log) {
        expect(array_keys($log->payload))->each->toBeIn(['corporate_group_id', 'members', 'this_company', 'user_id', 'role']);
    }

    foreach (cgPayloads($group) as $payload) {
        foreach ([$this->p, $this->a, $this->b] as $company) {
            expect($payload)->not->toContain($company->name)
                ->and($payload)->not->toContain((string) $company->org_number)
                ->and($payload)->not->toContain('"company_id"');
        }
    }
});

it('records a holding with separate capital and voting shares and logs it without the held company\'s name or number', function () {
    $holding = app(RecordHolding::class)($this->olle->fresh(), $this->p, [
        'held_org_number' => str_replace('-', '', (string) $this->a->org_number),
        'held_name' => 'Dotter A AB',
        'capital' => '62,5',
        'voting' => '80',
        'control_basis' => 'AGREEMENT_VOTES',
        'acquired_on' => '2025-03-14',
        'common_control' => true,
    ]);

    expect($holding->company_id)->toBe($this->p->id)
        ->and($holding->held_org_number)->toBe($this->a->org_number)
        ->and($holding->capital_bp)->toBe(6250)
        ->and($holding->voting_bp)->toBe(8000)
        ->and($holding->control_basis)->toBe(ControlBasis::AgreementVotes)
        ->and($holding->acquired_on->toDateString())->toBe('2025-03-14')
        ->and($holding->common_control)->toBeTrue();

    app(RecordHolding::class)($this->olle->fresh(), $this->p, [...cgHoldingInput($this->a, '80'), 'capital' => '62,5', 'held_name' => 'Nytt Namn AB', 'control_basis' => 'AGREEMENT_VOTES', 'acquired_on' => '2025-03-14', 'common_control' => true], $holding->id);

    $logs = AuditLog::query()->where('subject_type', $holding->getMorphClass())->orderBy('id')->get();

    expect($logs->pluck('action')->all())->toBe(['company_holding.created', 'company_holding.updated'])
        ->and($logs->pluck('company_id')->unique()->all())->toBe([$this->p->id])
        ->and($logs->last()->payload)->toEqual(['before' => [], 'after' => [], 'changed' => ['held_name']]);

    foreach ($logs as $log) {
        expect(json_encode($log->payload))->not->toContain('Dotter A AB')->not->toContain('Nytt Namn AB')
            ->not->toContain((string) $this->a->org_number)->not->toContain(str_replace('-', '', (string) $this->a->org_number));
    }
});

it('refuses invalid holdings', function (array $input, string $message) {
    expect(fn () => app(RecordHolding::class)($this->olle->fresh(), $this->p, [...cgHoldingInput($this->a, '100'), ...$input]))
        ->toThrow(ValidationException::class, $message)
        ->and(CurrentCompany::run($this->p, fn () => CompanyHolding::query()->count()))->toBe(0);
})->with([
    'votes over 100 %' => [['voting' => '100,01'], 'Andelen ska vara mellan 0 och 100 procent.'],
    'negative capital' => [['capital' => '-1'], 'Andelen ska vara mellan 0 och 100 procent.'],
    'bad number' => [['held_org_number' => '556000-0000'], 'Organisationsnumret stämmer inte.'],
    'personal number' => [['held_org_number' => '811218-9876'], 'inte ett personnummer'],
    'no control basis' => [['control_basis' => ''], 'Välj vad det bestämmande inflytandet grundar sig på.'],
    'unknown control basis' => [['control_basis' => 'MAJORITY'], 'control basis'],
    'disposed before acquired' => [['disposed_on' => '2023-12-31'], 'Avyttringsdagen kan inte vara före förvärvsdagen.'],
    'bad date' => [['acquired_on' => '1/1/2024'], 'förvärvsdag'],
]);

it('refuses a holding in the company itself and a second active holding in the same company', function () {
    expect(fn () => app(RecordHolding::class)($this->olle->fresh(), $this->p, cgHoldingInput($this->p, '100')))
        ->toThrow(ValidationException::class, 'Ett företag kan inte registrera ett innehav i sig självt.');

    cgHolding($this->olle, $this->p, $this->a, '60');

    expect(fn () => cgHolding($this->olle, $this->p, $this->a, '30'))->toThrow(ValidationException::class, 'Företaget har redan ett innehav i det här bolaget.');
});

it('lets only owners and administrators record holdings', function (string $role) {
    $user = User::factory()->create();
    $this->p->users()->attach($user, ['role' => $role]);

    expect(fn () => cgHolding($user, $this->p, $this->a))->toThrow(AuthorizationException::class);
})->with(['accountant', 'bookkeeper', 'approver', 'auditor']);

it('removes members, never the parent, and a holder only after the companies held through it', function () {
    cgHolding($this->olle, $this->p, $this->a);
    cgHolding($this->olle, $this->a, $this->c);
    $group = cgGroup($this);
    cgConfirm($this->olle, $group, $this->a);
    cgConfirm($this->olle, $group, $this->c);
    $remove = app(RemoveGroupMember::class);

    expect(fn () => $remove($this->olle->fresh(), $group->id, $this->p->id))->toThrow(ValidationException::class, 'Moderföretaget kan inte tas bort ur koncernen.')
        ->and(fn () => $remove($this->olle->fresh(), $group->id, $this->a->id))->toThrow(ValidationException::class, 'Andra företag i koncernen ägs genom det här företaget.')
        ->and(fn () => $remove($this->olle->fresh(), $group->id, $this->b->id))->toThrow(ValidationException::class, 'Företaget ingår inte i koncernen.');

    $remove($this->olle->fresh(), $group->id, $this->c->id);
    $remove($this->olle->fresh(), $group->id, $this->a->id);

    expect($group->members()->pluck('company_id')->all())->toBe([$this->p->id]);
});

it('lets a member company\'s own owner withdraw it without seeing the group', function () {
    cgHolding($this->olle, $this->p, $this->a);
    $group = cgGroup($this);
    cgConfirm($this->olle, $group, $this->a);

    expect(GroupAccess::for($this->aOwner->fresh())->levelFor($group))->toBe(GroupAccessLevel::None)
        ->and(fn () => app(RemoveGroupMember::class)($this->aOwner->fresh(), $group->id, $this->p->id))->toThrow(ModelNotFoundException::class);

    app(RemoveGroupMember::class)($this->aOwner->fresh(), $group->id, $this->a->id);

    expect($group->members()->pluck('company_id')->all())->toBe([$this->p->id]);
});

it('grants the viewer role to members of the parent only, and lets it be revoked or given up', function () {
    cgHolding($this->olle, $this->p, $this->a);
    $group = cgGroup($this);
    cgConfirm($this->olle, $group, $this->a);
    $viewer = User::factory()->create();
    $outsider = User::factory()->create();
    $this->p->users()->attach($viewer, ['role' => 'approver']);
    $grant = app(GrantGroupRole::class);

    expect(fn () => $grant($this->olle->fresh(), $group->id, $outsider->id))->toThrow(ValidationException::class, 'Personen måste vara medlem i moderföretaget.')
        ->and(fn () => $grant($this->olle->fresh(), $group->id, $outsider->id + 1000))->toThrow(ValidationException::class, 'Personen måste vara medlem i moderföretaget.')
        ->and(fn () => $grant($this->olle->fresh(), $group->id, $viewer->id, 'board'))->toThrow(ValidationException::class, 'Välj en roll.');

    $row = $grant($this->olle->fresh(), $group->id, $viewer->id);

    expect($grant($this->olle->fresh(), $group->id, $viewer->id)->id)->toBe($row->id)
        ->and(AuditLog::query()->where('action', 'corporate_group.role_granted')->count())->toBe(2)
        ->and(GroupAccess::for($viewer->fresh())->levelFor($group))->toBe(GroupAccessLevel::Consolidated);

    app(RevokeGroupRole::class)($viewer->fresh(), $group->id, $viewer->id);

    expect(CorporateGroupRole::query()->count())->toBe(0)
        ->and(GroupAccess::for($viewer->fresh())->levelFor($group))->toBe(GroupAccessLevel::None)
        ->and(fn () => app(RevokeGroupRole::class)($this->olle->fresh(), $group->id, $viewer->id))->toThrow(ValidationException::class, 'Personen har inte den rollen i koncernen.');
});

it('lets only whoever administers every member company grant or revoke a role', function () {
    cgHolding($this->olle, $this->p, $this->a);
    $group = cgGroup($this);
    cgConfirm($this->olle, $group, $this->a);
    $viewer = User::factory()->create();
    $this->p->users()->attach($viewer, ['role' => 'approver']);

    // The owner of P who is only accountant in A sees the group but does not administer it.
    $this->a->users()->updateExistingPivot($this->olle->id, ['role' => 'accountant']);

    expect(GroupAccess::for($this->olle->fresh())->levelFor($group))->toBe(GroupAccessLevel::Full)
        ->and(fn () => app(GrantGroupRole::class)($this->olle->fresh(), $group->id, $viewer->id))->toThrow(AuthorizationException::class);

    $this->a->users()->updateExistingPivot($this->olle->id, ['role' => 'admin']);
    app(GrantGroupRole::class)($this->olle->fresh(), $group->id, $viewer->id);
    $this->a->users()->updateExistingPivot($this->olle->id, ['role' => 'accountant']);

    expect(fn () => app(RevokeGroupRole::class)($this->olle->fresh(), $group->id, $viewer->id))->toThrow(AuthorizationException::class)
        ->and(CorporateGroupRole::query()->count())->toBe(1);
});

it('ignores members disposed of before the period when deciding full access', function () {
    $holding = cgHolding($this->olle, $this->p, $this->a);
    $group = cgGroup($this);
    cgConfirm($this->olle, $group, $this->a);
    app(RecordHolding::class)($this->olle->fresh(), $this->p, [...cgHoldingInput($this->a, '100'), 'disposed_on' => '2025-12-31'], $holding->id);

    $user = User::factory()->create();
    $this->p->users()->attach($user, ['role' => 'accountant']);
    $access = GroupAccess::for($user->fresh());

    expect($access->levelFor($group))->toBe(GroupAccessLevel::None)
        ->and($access->levelFor($group, CarbonImmutable::parse('2025-06-01')))->toBe(GroupAccessLevel::None)
        ->and($access->levelFor($group, CarbonImmutable::parse('2026-01-01')))->toBe(GroupAccessLevel::Full);
});

it('re-runs the 100 percent vote check whenever a holding of a group member is recorded or changed', function () {
    cgHolding($this->olle, $this->p, $this->b);
    $group = cgGroup($this);
    cgConfirm($this->olle, $group, $this->b);
    $ofP = cgHolding($this->olle, $this->p, $this->a, '50');

    // B is in P's group: B's 60 % next to P's 50 % would make 110 % of A's votes within the group.
    expect(fn () => cgHolding($this->olle, $this->b, $this->a, '60'))->toThrow(ValidationException::class, 'mer än 100 procent av rösterna i bolaget inom en koncern som företaget ingår i');

    cgHolding($this->olle, $this->b, $this->a, '50');

    expect(fn () => app(RecordHolding::class)($this->olle->fresh(), $this->p, cgHoldingInput($this->a, '51'), $ofP->id))->toThrow(ValidationException::class, 'mer än 100 procent av rösterna')
        ->and($ofP->fresh()?->voting_bp)->toBe(5000);

    // A disposed holding does not count.
    app(RecordHolding::class)($this->olle->fresh(), $this->p, [...cgHoldingInput($this->a, '50'), 'disposed_on' => '2025-01-01'], $ofP->id);

    expect(cgHolding($this->olle, $this->p, $this->a, '50')->voting_bp)->toBe(5000);
});

it('lets the company holding a member withdraw that membership without seeing the group', function () {
    cgHolding($this->olle, $this->p, $this->a);
    cgHolding($this->olle, $this->a, $this->c);
    $group = cgGroup($this);
    cgConfirm($this->olle, $group, $this->a);
    cgConfirm($this->olle, $group, $this->c);
    $stranger = User::factory()->withCompany()->create();

    expect(GroupAccess::for($this->aOwner->fresh())->levelFor($group))->toBe(GroupAccessLevel::None)
        ->and(fn () => app(RemoveGroupMember::class)($stranger->fresh(), $group->id, $this->c->id))->toThrow(ModelNotFoundException::class);

    // A's owner may take out C, which is held through A's own holding.
    app(RemoveGroupMember::class)($this->aOwner->fresh(), $group->id, $this->c->id);

    expect($group->members()->pluck('company_id')->all())->toEqualCanonicalizing([$this->p->id, $this->a->id]);
});

it('freezes administration while a member is archived, until that member is removed by the group or withdrawn by its owner', function () {
    cgHolding($this->olle, $this->p, $this->a);
    cgHolding($this->olle, $this->p, $this->b);
    $group = cgGroup($this);
    cgConfirm($this->olle, $group, $this->a);
    cgConfirm($this->olle, $group, $this->b);
    $this->a->forceFill(['archived_at' => now()])->save();
    $viewer = User::factory()->create();
    $this->p->users()->attach($viewer, ['role' => 'approver']);

    cgHolding($this->olle, $this->p, $this->c);

    expect(GroupAccess::for($this->olle->fresh())->administers($group))->toBeFalse()
        ->and(fn () => app(GrantGroupRole::class)($this->olle->fresh(), $group->id, $viewer->id))->toThrow(AuthorizationException::class)
        ->and(fn () => cgConfirm($this->olle, $group, $this->c))->toThrow(AuthorizationException::class);

    // Petra administers P and B and sees A's reports, but administers neither A nor anything archived:
    // she may take out the archived A, and only A.
    $petra = User::factory()->create();
    $this->p->users()->attach($petra, ['role' => 'admin']);
    $this->b->users()->attach($petra, ['role' => 'admin']);
    $this->a->users()->attach($petra, ['role' => 'accountant']);
    $this->b->users()->updateExistingPivot($this->olle->id, ['role' => 'accountant']);

    expect(fn () => app(GrantGroupRole::class)($petra->fresh(), $group->id, $viewer->id))->toThrow(AuthorizationException::class);

    app(RemoveGroupMember::class)($petra->fresh(), $group->id, $this->a->id);
    $this->b->users()->updateExistingPivot($this->olle->id, ['role' => 'admin']);

    expect(GroupAccess::for($this->olle->fresh())->administers($group->fresh()))->toBeTrue();

    $this->b->forceFill(['archived_at' => now()])->save();
    app(RemoveGroupMember::class)($this->bOwner->fresh(), $group->id, $this->b->id);

    expect($group->members()->pluck('company_id')->all())->toBe([$this->p->id]);
});

it('keeps a viewer role through ownership transfers of the parent and never revives a stale one', function () {
    cgHolding($this->olle, $this->p, $this->a);
    $group = cgGroup($this);
    cgConfirm($this->olle, $group, $this->a);
    $viggo = User::factory()->create(['name' => 'Viggo']);
    $this->p->users()->attach($viggo, ['role' => 'approver']);
    app(GrantGroupRole::class)($this->olle->fresh(), $group->id, $viggo->id);
    $level = fn (User $user): GroupAccessLevel => GroupAccess::for($user->fresh())->levelFor($group->fresh());

    // Viggo becomes owner of P (he has no reports:view in A, so only the role shows him the group).
    app(TransferOwnership::class)($this->olle->fresh(), $this->p->fresh(), $viggo->id);

    expect($level($viggo))->toBe(GroupAccessLevel::Consolidated)
        ->and(CorporateGroupRole::query()->sole()->parent_membership_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'corporate_group.roles_rebound')->where('company_id', $this->p->id)->count())->toBe(1);

    // And back: Viggo keeps the role as administrator, bound to his new membership.
    app(TransferOwnership::class)($viggo->fresh(), $this->p->fresh(), $this->olle->id);

    expect($level($viggo))->toBe(GroupAccessLevel::Consolidated)
        ->and(CorporateGroupRole::query()->sole()->parent_membership_id)->toBe((int) DB::table('team_user')->where('team_id', $this->p->id)->where('user_id', $viggo->id)->value('id'));

    // He leaves P and is invited again: no role. Made owner once more: still none, the stale role stays stale.
    $this->p->users()->detach($viggo);
    $this->p->users()->attach($viggo, ['role' => 'approver']);

    expect($level($viggo))->toBe(GroupAccessLevel::None);

    app(TransferOwnership::class)($this->olle->fresh(), $this->p->fresh(), $viggo->id);

    expect($level($viggo))->toBe(GroupAccessLevel::None);
});
