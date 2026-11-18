<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupMember;
use App\Models\CorporateGroupRole;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The structure of a shared group for the screen Koncern → Översikt (ADR-081
 * E22, ADR-060 S2-1.2): who holds whom, with capital and votes, acquisition
 * and disposal dates, and the state of each company's year. Reads only.
 *
 * What a user gets is decided here from GroupAccess::levelFor(), every time:
 *
 * - FULL: the whole structure, per-company period status.
 * - CONSOLIDATED: names, who holds whom and the share of the capital, and
 *   whether a holding gives control at all (no_control), nothing else
 *   (ADR-060 2.2 and its E22 addendum). No votes, control basis, dates,
 *   organisation numbers or period status of any company is ever put in the
 *   payload.
 * - NONE: a 404 (GroupAccess::corporateGroup), never reaches this class.
 *
 * The group's own rows (members, roles) are read through GroupAccess. The
 * companies' holdings and fiscal years are company data, so they are read
 * with one scope bypass each, restricted to the ids GroupAccess lets this
 * user see; the number of queries does not depend on the number of members.
 */
final class CorporateGroupOverview
{
    /**
     * @return array{
     *     level: GroupAccessLevel,
     *     nodes: list<array<string, mixed>>,
     *     roles: list<array{user_id: int, name: string, email: string}>,
     *     candidates: list<array{id: int, name: string}>,
     *     administers: bool
     * }
     */
    public function build(GroupAccess $access, CorporateGroup $group): array
    {
        $level = $access->levelFor($group);

        if (! $level->sees()) {
            throw new \LogicException('A group the user does not see is never built; GroupAccess::corporateGroup answers 404 first.');
        }

        $full = $level === GroupAccessLevel::Full;
        $members = $group->members;
        $companyIds = array_values(array_map(fn (CorporateGroupMember $member): int => $member->company_id, $members->all()));
        $companies = Company::query()->whereIn('id', $companyIds)->get()->keyBy('id');
        $holdings = $this->holdings($members);
        $years = $full ? $this->currentYears($companyIds) : collect();
        $administers = $full && $access->administers($group);

        $nodes = [];

        foreach ($members as $member) {
            $company = $companies->get($member->company_id);

            if (! $company instanceof Company) {
                continue;
            }

            $holding = $member->holding_id === null ? null : $holdings->get($member->holding_id);
            $isParent = $member->company_id === $group->parent_company_id;

            $node = [
                'company_id' => $company->id,
                'name' => $company->name,
                'holder_id' => $holding?->company_id,
                // Only a holder that is itself a member of the group is named.
                'holder_name' => $holding !== null && in_array($holding->company_id, $companyIds, true) ? $companies->get($holding->company_id)?->name : null,
                'is_parent' => $isParent,
                'capital_bp' => $holding?->capital_bp,
                // Shown at every access level: the holding does not make it a subsidiary. The basis itself is FULL only.
                'no_control' => $holding !== null && ! $holding->control_basis->givesControl(),
            ];

            if ($full) {
                $year = $years->get($company->id);
                $node += [
                    'org_number' => $company->org_number,
                    'voting_bp' => $holding?->voting_bp,
                    'control_basis' => $holding?->control_basis,
                    'acquired_on' => $holding?->acquired_on,
                    'disposed_on' => $holding?->disposed_on,
                    'year' => $year instanceof FiscalYear ? $year->label() : null,
                    'year_status' => $year instanceof FiscalYear ? $year->status : null,
                    'locked_until' => $company->locked_until_date,
                    'archived' => $company->isArchived(),
                ];
            }

            $nodes[] = $node;
        }

        return [
            'level' => $level,
            'nodes' => $this->tree($nodes, $group->parent_company_id),
            'roles' => $administers ? $this->roles($group) : [],
            'candidates' => $administers ? $this->candidates($group) : [],
            'administers' => $administers,
        ];
    }

    /**
     * The holdings that ground the group's memberships, by id.
     *
     * @param  Collection<int, CorporateGroupMember>  $members
     * @return Collection<int, CompanyHolding>
     */
    private function holdings(Collection $members): Collection
    {
        $ids = $members->pluck('holding_id')->filter()->values()->all();

        if ($ids === []) {
            return collect();
        }

        // Across the group's members on purpose, in one query: only the holdings that make these
        // companies members (ids from the group's own rows, which the caller may see at all, see
        // GroupAccess::corporateGroup) and only the columns the access level allows are put in the payload.
        // Nothing is written, nothing outside the group is read.
        return CompanyScope::bypass(fn (): Collection => CompanyHolding::query()->whereIn('id', $ids)->get()->keyBy('id'));
    }

    /**
     * The fiscal year each company is in today, or its latest: the one containing today, else the
     * most recent start. Called only for FULL access, whose rule requires reports:view in every one
     * of these companies.
     *
     * @param  list<int>  $companyIds
     * @return Collection<int, FiscalYear>
     */
    private function currentYears(array $companyIds): Collection
    {
        $today = CarbonImmutable::today();

        // Across the group's members on purpose, in one query: the user has reports:view in every one of
        // them (GroupAccessLevel::Full). Only the year's dates and status reach the screen.
        $years = CompanyScope::bypass(fn (): Collection => FiscalYear::query()
            ->whereIn('company_id', $companyIds)
            ->where('start_date', '<=', $today->toDateString())
            ->orderByDesc('start_date')
            ->get());

        return $years->groupBy('company_id')->map(function (Collection $rows) use ($today): FiscalYear {
            $containing = $rows->first(fn (FiscalYear $year): bool => $year->contains($today));

            return $containing instanceof FiscalYear ? $containing : $rows->firstOrFail();
        });
    }

    /**
     * Depth-first from the parent: a member sits under the member that holds it.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function tree(array $nodes, int $parentId): array
    {
        $ids = array_column($nodes, 'company_id');
        $children = [];

        foreach ($nodes as $node) {
            $holder = $node['holder_id'];
            $under = $node['is_parent'] ? null : (in_array($holder, $ids, true) && $holder !== $node['company_id'] ? $holder : $parentId);
            $children[$under ?? 0][] = $node;
        }

        $ordered = [];
        $seen = [];
        $walk = function (int $key, int $depth) use (&$walk, &$ordered, &$seen, $children): void {
            $siblings = $children[$key] ?? [];
            usort($siblings, fn (array $a, array $b): int => mb_strtolower((string) $a['name']) <=> mb_strtolower((string) $b['name']));

            foreach ($siblings as $node) {
                if (isset($seen[$node['company_id']])) {
                    continue;
                }

                $seen[$node['company_id']] = true;
                $ordered[] = [...$node, 'depth' => $depth];
                $walk($node['company_id'], $depth + 1);
            }
        };
        $walk(0, 0);

        // A member that could not be placed (a holder loop) is still listed, below the parent.
        foreach ($nodes as $node) {
            if (! isset($seen[$node['company_id']])) {
                $ordered[] = [...$node, 'depth' => 1];
            }
        }

        return $ordered;
    }

    /**
     * Who has a group role, for whoever administers the group.
     *
     * @return list<array{user_id: int, name: string, email: string}>
     */
    private function roles(CorporateGroup $group): array
    {
        $roles = $group->roles()->get();
        $users = User::query()->whereIn('id', $roles->pluck('user_id')->all())->orderBy('name')->get();

        return array_values($users->map(fn (User $user): array => ['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email])->all());
    }

    /**
     * The people the group role can be given to: the parent's members who do not have it yet.
     *
     * @return list<array{id: int, name: string}>
     */
    private function candidates(CorporateGroup $group): array
    {
        $holders = CorporateGroupRole::query()->where('corporate_group_id', $group->id)->pluck('user_id')->all();
        $parent = Company::query()->findOrFail($group->parent_company_id);

        return array_values($parent->allUsers()
            ->reject(fn (User $user): bool => in_array($user->id, $holders, true))
            ->sortBy('name')
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all());
    }
}
