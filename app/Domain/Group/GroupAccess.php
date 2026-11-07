<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Domain\Firm\Memberships;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\CompanyGroupMember;
use App\Models\CompanyHolding;
use App\Models\CompanyMembership;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupMember;
use App\Models\CorporateGroupRole;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Who may see what in a group (ADR-060, AGENTS.md §1.3). A group belongs to
 * one user and lists company ids; whether a company's data may be shown is
 * decided here, every time, from the user's memberships: a member with
 * reports:view in THAT company. Ids from the client are never trusted, and
 * a company the user has left drops out at once.
 */
final class GroupAccess
{
    public const string PERMISSION = 'reports:view';

    /** @var ?array<int, int> the user's team_user id per company, read once when a group role is checked */
    private ?array $membershipIds = null;

    private function __construct(public readonly User $user, private readonly Memberships $memberships) {}

    public static function for(User $user): self
    {
        return new self($user, Memberships::of($user));
    }

    /** @return Collection<int, CompanyGroup> */
    public function groups(): Collection
    {
        return CompanyGroup::query()->where('user_id', $this->user->id)->orderBy('name')->get();
    }

    /** Another user's group answers 404, like any record of another tenant. */
    public function group(int $id): CompanyGroup
    {
        return CompanyGroup::query()->where('user_id', $this->user->id)->findOrFail($id);
    }

    public function mayView(int $companyId): bool
    {
        return $this->memberships->may($companyId, self::PERMISSION);
    }

    /** @return list<int> */
    public function viewableIds(): array
    {
        return $this->memberships->where(self::PERMISSION);
    }

    /** @return SupportCollection<int, Company> companies the user may pick for a group, by name */
    public function pickable(): SupportCollection
    {
        return $this->memberships->companies->filter(fn (Company $company): bool => $this->mayView($company->id))->values();
    }

    /**
     * The members of the group the user may see, with their companies, and
     * how many were left out because the user has no access to them.
     *
     * @return array{members: SupportCollection<int, array{member: CompanyGroupMember, company: Company}>, hidden: int}
     */
    public function members(CompanyGroup $group): array
    {
        $members = $group->members()->get();
        $visible = collect();

        foreach ($members as $member) {
            $company = $this->memberships->companies->firstWhere('id', $member->company_id);

            if ($company instanceof Company && $this->mayView($company->id)) {
                $visible->push(['member' => $member, 'company' => $company]);
            }
        }

        return [
            'members' => $visible->sortBy(fn (array $row): string => mb_strtolower($row['company']->name))->values(),
            'hidden' => $members->count() - $visible->count(),
        ];
    }

    /*
     * Shared groups (ADR-060, addendum 2026-11-07, 2.2). All or nothing at
     * group level: FULL with reports:view in every member company, otherwise
     * CONSOLIDATED with an explicit group role while still a member of the
     * parent, otherwise NONE. Decided from the memberships on every call;
     * nothing is cached and no stored flag outlives a membership.
     */

    /** The roles that may administer a group: owner or administrator in every member company. */
    public const array ADMIN_ROLES = ['owner', 'admin'];

    /**
     * What the user may see of the group. With $from, members whose holding
     * was disposed of before that day are not required.
     */
    public function levelFor(CorporateGroup $group, ?CarbonImmutable $from = null): GroupAccessLevel
    {
        $required = $this->includedCompanyIds($group, $from);

        if ($required !== [] && array_all($required, fn (int $companyId): bool => $this->mayView($companyId))) {
            return GroupAccessLevel::Full;
        }

        if ($this->memberships->has($group->parent_company_id) && $this->hasRole($group, GroupRole::Viewer)) {
            return GroupAccessLevel::Consolidated;
        }

        return GroupAccessLevel::None;
    }

    /**
     * Owner or administrator, live, in every member company and the parent.
     * An archived company cannot be administered, so an archived member
     * freezes the group's administration until it leaves; $except names the
     * companies to leave out of the check (only the archived member being
     * removed, RemoveGroupMember).
     *
     * @param  list<int>  $except
     */
    public function administers(CorporateGroup $group, array $except = []): bool
    {
        $ids = array_diff(array_unique([$group->parent_company_id, ...$this->memberIds($group)]), $except);

        return array_all($ids, fn (int $companyId): bool => $this->mayAdminister($companyId));
    }

    /** Owner or administrator of the company, archived or not: enough to take the company's own consent back. */
    public function mayWithdraw(int $companyId): bool
    {
        return $this->memberships->has($companyId) && in_array($this->memberships->role($companyId), self::ADMIN_ROLES, true);
    }

    /** A member of the company in any role. */
    public function isMember(int $companyId): bool
    {
        return $this->memberships->has($companyId);
    }

    /** Owner or administrator of the company, which is not archived. */
    public function mayAdminister(int $companyId): bool
    {
        if (! $this->memberships->has($companyId)) {
            return false;
        }

        if ($this->memberships->companies->firstWhere('id', $companyId)?->isArchived()) {
            return false;
        }

        return in_array($this->memberships->role($companyId), self::ADMIN_ROLES, true);
    }

    /** @return Collection<int, CorporateGroup> the shared groups the user sees at all, by name */
    public function corporateGroups(): Collection
    {
        $companyIds = $this->memberships->ids();

        return CorporateGroup::query()
            ->where(fn ($query) => $query
                ->whereIn('id', CorporateGroupMember::query()->select('corporate_group_id')->whereIn('company_id', $companyIds))
                ->orWhereIn('id', CorporateGroupRole::query()->select('corporate_group_id')->where('user_id', $this->user->id)))
            ->with(['members', 'roles' => fn ($query) => $query->where('user_id', $this->user->id)])
            ->orderBy('name')
            ->get()
            ->filter(fn (CorporateGroup $group): bool => $this->levelFor($group)->sees())
            ->values();
    }

    /** A group the user does not see answers 404, exactly like one that does not exist. */
    public function corporateGroup(int $id): CorporateGroup
    {
        $group = CorporateGroup::query()->with('members')->find($id);

        if (! $group instanceof CorporateGroup || ! $this->levelFor($group)->sees()) {
            throw (new ModelNotFoundException)->setModel(CorporateGroup::class, [$id]);
        }

        return $group;
    }

    /** @return list<int> */
    private function memberIds(CorporateGroup $group): array
    {
        $members = $group->relationLoaded('members') ? $group->members : $group->members()->get();

        return array_values(array_map(fn (CorporateGroupMember $member): int => $member->company_id, $members->all()));
    }

    /**
     * THE inclusion rule (ADR-060, "S2-1.1 as built"): the companies of the
     * group that a period starting on $from includes, the parent always.
     * Members whose holding was disposed of strictly before $from are left
     * out. FULL access needs reports:view in exactly these companies, and the
     * consolidation (S2-1.4) must include exactly these, so access and figures
     * never disagree.
     *
     * @return list<int>
     */
    public function includedCompanyIds(CorporateGroup $group, ?CarbonImmutable $from = null): array
    {
        $members = $group->relationLoaded('members') ? $group->members : $group->members()->get();
        $disposed = [];

        if ($from !== null) {
            $holdingIds = $members->pluck('holding_id')->filter()->values()->all();

            // Only the disposal date of the holdings that made these companies members is read, to
            // decide which members the period needs; nothing of the holding is shown to the user.
            $disposed = $holdingIds === [] ? [] : CompanyScope::bypass(fn (): array => CompanyHolding::query()
                ->whereIn('id', $holdingIds)
                ->where('disposed_on', '<', $from->toDateString())
                ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        }

        $required = $members
            ->reject(fn (CorporateGroupMember $member): bool => $member->holding_id !== null && in_array($member->holding_id, $disposed, true))
            ->map(fn (CorporateGroupMember $member): int => $member->company_id)
            ->all();

        return array_values(array_unique([$group->parent_company_id, ...$required]));
    }

    /**
     * A role counts only for the membership in the parent it was granted
     * under: one that outlived its holder's membership does not revive when
     * the person is invited again, however soon.
     */
    private function hasRole(CorporateGroup $group, GroupRole $role): bool
    {
        $rows = $group->relationLoaded('roles')
            ? $group->roles->where('user_id', $this->user->id)->where('role', $role)
            : $group->roles()->where('user_id', $this->user->id)->where('role', $role->value)->get();

        return $rows->contains(fn (CorporateGroupRole $row): bool => $this->grantedUnderCurrentMembership($row, $group->parent_company_id));
    }

    private function grantedUnderCurrentMembership(CorporateGroupRole $row, int $parentId): bool
    {
        if (! $this->memberships->has($parentId)) {
            return false;
        }

        $isOwner = $this->memberships->role($parentId) === 'owner';

        if ($row->parent_membership_id === null) {
            return $isOwner;
        }

        $this->membershipIds ??= CompanyMembership::query()->where('user_id', $this->user->id)
            ->pluck('id', 'team_id')->mapWithKeys(fn (mixed $id, mixed $teamId): array => [(int) $teamId => (int) $id])->all();

        return ! $isOwner && ($this->membershipIds[$parentId] ?? null) === $row->parent_membership_id;
    }
}
