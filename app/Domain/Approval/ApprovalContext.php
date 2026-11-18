<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Models\ApprovalDelegation;
use App\Models\ApprovalPolicy;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Laravel\Jetstream\Jetstream;
use Laravel\Jetstream\Role;

/**
 * Everything the engine needs about one company, read in a fixed number of
 * queries however many subjects it is asked about: the active policies with
 * their steps, the members and their roles, the delegations in force today
 * and the owners of cost centres and projects. Membership and roles are read
 * when the context is made, so a person who has left or lost the role cannot
 * decide (ADR-081 gap 8, "re-checked live").
 */
final class ApprovalContext
{
    /** @var array<int, string> member id => role key */
    private array $roles = [];

    /**
     * @param  Collection<int, ApprovalPolicy>  $policies
     * @param  Collection<int, ApprovalDelegation>  $delegations
     * @param  array<int, int>  $costCenterOwners  cost centre id => owner user id
     * @param  array<int, int>  $projectOwners  project id => owner user id
     */
    public function __construct(
        public readonly Company $company,
        public readonly Collection $policies,
        public readonly Collection $delegations,
        public readonly array $costCenterOwners,
        public readonly array $projectOwners,
        public readonly CarbonImmutable $today,
    ) {
        foreach ($company->users as $member) {
            /** @var User $member */
            $role = $member->getRelationValue('membership')?->getAttribute('role');
            $this->roles[(int) $member->id] = is_string($role) ? $role : '';
        }
    }

    /** Six queries: policies, steps, members, delegations, cost centre and project owners. */
    public static function load(Company $company): self
    {
        // A copy, so the members read here are fresh and the caller's company keeps its own relations.
        $company = (clone $company)->unsetRelation('users')->load('users');

        return new self(
            company: $company,
            policies: ApprovalPolicy::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->with('steps')
                ->orderBy('priority')
                ->orderBy('id')
                ->get(),
            delegations: ApprovalDelegation::query()
                ->where('company_id', $company->id)
                ->inForce(CarbonImmutable::today())
                ->orderBy('id')
                ->get(),
            costCenterOwners: array_map('intval', CostCenter::query()->where('company_id', $company->id)->whereNotNull('owner_user_id')->pluck('owner_user_id', 'id')->all()),
            projectOwners: array_map('intval', Project::query()->where('company_id', $company->id)->whereNotNull('owner_user_id')->pluck('owner_user_id', 'id')->all()),
            today: CarbonImmutable::today(),
        );
    }

    /** @return Collection<int, ApprovalPolicy> */
    public function policiesFor(ApprovalSubjectType $type): Collection
    {
        return $this->policies->filter(fn (ApprovalPolicy $policy): bool => $policy->subject_type === $type)->values();
    }

    /**
     * The ids of the company's members and its owner, read now.
     *
     * @return list<int>
     */
    public static function memberIds(Company $company): array
    {
        $ids = $company->users()->pluck('users.id')->map(fn (mixed $id): int => (int) $id)->all();
        $ids[] = (int) $company->user_id;

        return array_values(array_unique($ids));
    }

    public function isMember(int $userId): bool
    {
        return $userId === (int) $this->company->user_id || array_key_exists($userId, $this->roles);
    }

    /**
     * Whether a member holds a core permission through their role in the
     * company, read from the membership loaded with the context. Used for
     * the person a substitute decides for; the decider's own permissions go
     * through User::hasTeamPermission.
     */
    public function memberHas(int $userId, string $permission): bool
    {
        if ($userId === (int) $this->company->user_id) {
            return true;
        }

        $role = Jetstream::findRole($this->roles[$userId] ?? '');

        return $role instanceof Role && in_array($permission, (array) $role->permissions, true);
    }

    /**
     * The owners of the subject's cost centres or projects who are members
     * holding the subject's own approval permission.
     *
     * @return list<int>
     */
    public function dimensionOwners(?ApprovalDimension $dimension, SubjectFacts $facts): array
    {
        [$owners, $ids] = $dimension === ApprovalDimension::Project
            ? [$this->projectOwners, $facts->projectIds]
            : [$this->costCenterOwners, $facts->costCenterIds];

        $found = [];

        foreach ($ids as $id) {
            $owner = $owners[$id] ?? null;

            if ($owner !== null && $this->isMember($owner) && $this->memberHas($owner, $facts->type->basePermission())) {
                $found[] = $owner;
            }
        }

        return array_values(array_unique($found));
    }

    /** @return Collection<int, ApprovalDelegation> */
    public function delegationsTo(int $substituteId): Collection
    {
        return $this->delegations->filter(fn (ApprovalDelegation $delegation): bool => $delegation->substitute_user_id === $substituteId)->values();
    }

    public function separateApproval(): bool
    {
        return (bool) $this->company->separate_supplier_approval;
    }
}
