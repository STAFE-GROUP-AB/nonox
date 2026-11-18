<?php

declare(strict_types=1);

use App\Actions\Company\SetDimensionScope;
use App\Domain\Company\CompanyOverview;
use App\Domain\Firm\Memberships;
use App\Domain\Group\GroupAccess;
use App\Models\AuditLog;
use App\Models\CompanyMembership;
use App\Models\CostCenter;
use App\Models\MembershipDimensionScope;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Actions\UpdateTeamMemberRole;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;
use Tests\Support\Bookkeeping;
use Tests\Support\DimensionScopeFixture;

/*
 * ADR-081 gap 10: who can be limited, who sets it, and that a scope belongs
 * to one membership. Owner, admin, accountant and auditor are never limited.
 */
beforeEach(function () {
    $this->fixture = DimensionScopeFixture::make();
    $this->actingAs($this->fixture->owner);
});

it('limits a bookkeeper to the chosen cost centres and projects', function () {
    $member = $this->fixture->member();
    $scope = DimensionScope::for($member, $this->fixture->company);

    expect($scope->restricted)->toBeTrue()
        ->and($scope->costCenterIds)->toBe([$this->fixture->north->id])
        ->and($scope->projectIds)->toBe([$this->fixture->rebuild->id])
        ->and($member->hasTeamPermission($this->fixture->company, 'vouchers:view'))->toBeTrue()
        ->and($member->hasTeamPermission($this->fixture->company, 'reports:view'))->toBeTrue()
        // Narrowed: a whole-company permission of the role is gone.
        ->and($member->hasTeamPermission($this->fixture->company, 'invoices:view'))->toBeFalse()
        ->and($member->hasTeamPermission($this->fixture->company, 'payments:create'))->toBeFalse()
        ->and($member->hasTeamPermission($this->fixture->company, 'vouchers:correct'))->toBeFalse()
        // Pages that span companies show each one whole: a limited membership gives nothing there.
        ->and(Memberships::of($member)->may($this->fixture->company->id, 'invoices:view'))->toBeFalse()
        ->and(Memberships::of($member)->may($this->fixture->company->id, 'vouchers:view'))->toBeFalse()
        ->and(Memberships::of($member)->has($this->fixture->company->id))->toBeTrue();
});

it('never widens a role', function () {
    $approver = $this->fixture->member('approver');

    expect($approver->hasTeamPermission($this->fixture->company, 'vouchers:create'))->toBeFalse()
        ->and($approver->hasTeamPermission($this->fixture->company, 'reports:view'))->toBeFalse()
        ->and($approver->hasTeamPermission($this->fixture->company, 'supplier-invoices:approve'))->toBeTrue();
});

it('refuses to limit owner, admin, accountant and auditor', function (string $role) {
    $member = Bookkeeping::member($this->fixture->company, $role);

    expect(fn () => app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $member->id, [$this->fixture->north->id], []))
        ->toThrow(ValidationException::class, 'kan inte begränsas');

    // Even a flag set behind the action's back leaves them unlimited.
    DB::table('team_user')->where('team_id', $this->fixture->company->id)->where('user_id', $member->id)->update(['dimension_scoped' => true]);

    expect(DimensionScope::for($member->fresh(), $this->fixture->company)->restricted)->toBeFalse()
        ->and($member->fresh()->hasTeamPermission($this->fixture->company, 'export:sie'))->toBe($role !== 'bookkeeper');
})->with(['admin', 'accountant', 'auditor']);

it('never limits the owner', function () {
    expect(fn () => app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $this->fixture->owner->id, [$this->fixture->north->id], []))
        ->toThrow(ValidationException::class)
        ->and(DimensionScope::for($this->fixture->owner, $this->fixture->company)->restricted)->toBeFalse();
});

it('lets only the owner and admins set a scope', function () {
    $admin = Bookkeeping::member($this->fixture->company, 'admin');
    $accountant = Bookkeeping::member($this->fixture->company, 'accountant');
    $target = Bookkeeping::member($this->fixture->company, 'bookkeeper');

    app(SetDimensionScope::class)($admin, $this->fixture->company, $target->id, [$this->fixture->north->id], []);

    expect(DimensionScope::for($target->fresh(), $this->fixture->company)->costCenterIds)->toBe([$this->fixture->north->id])
        ->and(fn () => app(SetDimensionScope::class)($accountant, $this->fixture->company, $target->id, [], []))->toThrow(AuthorizationException::class);
});

it('keeps a unit manager limited by the role, reaching nothing without units', function () {
    $manager = Bookkeeping::member($this->fixture->company, 'unit_manager');
    $scope = DimensionScope::for($manager, $this->fixture->company);

    expect($scope->restricted)->toBeTrue()
        ->and($scope->costCenterIds)->toBe([])
        ->and($scope->allowsRow($this->fixture->north->id, null))->toBeFalse()
        ->and($manager->hasTeamPermission($this->fixture->company, 'reports:view'))->toBeTrue()
        ->and($manager->hasTeamPermission($this->fixture->company, 'vouchers:view'))->toBeFalse();
});

it('lifts the scope when the role becomes one that is never limited', function () {
    $member = $this->fixture->member();

    app(UpdateTeamMemberRole::class)->update($this->fixture->owner, $this->fixture->company, $member->id, 'accountant');
    DimensionScope::forget();

    expect(DimensionScope::for($member->fresh(), $this->fixture->company)->restricted)->toBeFalse()
        ->and(CurrentCompany::run($this->fixture->company, fn () => MembershipDimensionScope::query()->where('user_id', $member->id)->count()))->toBe(0)
        ->and(AuditLog::query()->where('action', 'membership.dimension_scope_lifted')->latest('id')->first()?->payload)->toMatchArray(['user_id' => $member->id, 'reason' => 'role_changed']);
});

it('does not revive a scope when the person leaves and is invited again', function () {
    $member = $this->fixture->member();
    $oldMembership = (int) CompanyMembership::query()->where('team_id', $this->fixture->company->id)->where('user_id', $member->id)->value('id');

    app(RemovesTeamMembers::class)->remove($this->fixture->owner, $this->fixture->company, $member);

    // A row left from the old membership (written before the clean-up existed) must not match the new one.
    CurrentCompany::run($this->fixture->company, fn () => MembershipDimensionScope::create([
        'user_id' => $member->id, 'team_user_id' => $oldMembership, 'dimension' => 'COST_CENTER', 'object_id' => $this->fixture->north->id,
    ]));

    $this->fixture->company->users()->attach($member, ['role' => 'unit_manager']);
    DimensionScope::forget();
    $again = $member->fresh();

    expect(DimensionScope::for($again, $this->fixture->company)->restricted)->toBeTrue()
        ->and(DimensionScope::for($again, $this->fixture->company)->costCenterIds)->toBe([]);
});

it('refuses another company\'s cost centre id', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    $foreign = CurrentCompany::run($other, fn () => CostCenter::create(['code' => '99', 'name' => 'Annat bolag']));
    $member = Bookkeeping::member($this->fixture->company, 'bookkeeper');

    expect(fn () => app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $member->id, [$foreign->id], []))
        ->toThrow(ValidationException::class, 'Välj kostnadsställen i företaget.')
        ->and(DimensionScope::for($member->fresh(), $this->fixture->company)->restricted)->toBeFalse();
});

it('answers the same for a person outside the company as for an unknown id', function () {
    [$stranger] = Bookkeeping::company();

    foreach ([$stranger->id, 999_999_999] as $id) {
        expect(fn () => app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $id, [], []))
            ->toThrow(ValidationException::class, 'Välj en person i företaget.');
    }
});

it('keeps another company\'s scope rows invisible', function () {
    $this->fixture->member();
    $other = DimensionScopeFixture::make();
    $other->member();

    expect(CurrentCompany::run($this->fixture->company, fn () => MembershipDimensionScope::query()->pluck('company_id')->unique()->all()))->toBe([$this->fixture->company->id])
        ->and(CurrentCompany::run($other->company, fn () => MembershipDimensionScope::query()->pluck('company_id')->unique()->all()))->toBe([$other->company->id]);
});

it('writes ids and counts only to the audit log', function () {
    $member = $this->fixture->member();
    $entry = AuditLog::query()->where('action', 'membership.dimension_scope_set')->latest('id')->firstOrFail();

    expect($entry->company_id)->toBe($this->fixture->company->id)
        ->and($entry->payload)->toEqual([
            'user_id' => $member->id,
            'role' => 'bookkeeper',
            'cost_center_ids' => [$this->fixture->north->id],
            'project_ids' => [$this->fixture->rebuild->id],
        ]);
});

it('lifts the limit when nothing is chosen', function () {
    $member = $this->fixture->member();

    app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $member->id, [], []);

    expect(DimensionScope::for($member->fresh(), $this->fixture->company)->restricted)->toBeFalse()
        ->and($member->fresh()->hasTeamPermission($this->fixture->company, 'invoices:view'))->toBeTrue();
});

it('shows nothing of a company where the member is limited on pages that span companies', function () {
    $member = $this->fixture->member();

    $rows = collect(app(CompanyOverview::class)->for($member->fresh()))->keyBy(fn (array $row): int => $row['company']->id);

    expect($rows->get($this->fixture->company->id)['tasks'])->toBe([])
        ->and($rows->get($this->fixture->company->id)['last_posted'])->toBeNull()
        ->and(GroupAccess::for($member->fresh())->mayView($this->fixture->company->id))->toBeFalse();
});

it('lists the scope in the person\'s own register extract', function () {
    $member = $this->fixture->member();
    $unlimited = Bookkeeping::member($this->fixture->company, 'bookkeeper');

    $limited = $this->actingAs($member)->get(route('privacy.export.me'))->assertOk()->json('foretag');
    $free = $this->actingAs($unlimited)->get(route('privacy.export.me'))->assertOk()->json('foretag');

    expect(collect($limited)->firstWhere('namn', $this->fixture->company->name)['begransad_till'])->toBe([
        'kostnadsstallen' => ['10 Butik Norr'],
        'projekt' => ['P1 Ombyggnad'],
    ])
        ->and(collect($free)->firstWhere('namn', $this->fixture->company->name)['begransad_till'])->toBeNull();
});
