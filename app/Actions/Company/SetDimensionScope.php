<?php

declare(strict_types=1);

namespace App\Actions\Company;

use App\Domain\Company\ScopeDimension;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\CostCenter;
use App\Models\MembershipDimensionScope;
use App\Models\Project;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Limits a member to a set of cost centres and projects, or lifts the limit
 * (ADR-081 gap 10). Only with members:scope (owner and admin). Owner, admin,
 * accountant and auditor can never be limited (owner decision 22). The scope
 * replaces the member's earlier one as a whole and belongs to their current
 * membership (team_user.id), so it is never revived after they leave and are
 * invited again. With no cost centre and no project the limit is lifted; a
 * unit manager is limited by the role and then reaches nothing.
 */
final class SetDimensionScope
{
    /**
     * @param  list<int>  $costCenterIds
     * @param  list<int>  $projectIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, Company $company, int $memberId, array $costCenterIds, array $projectIds): void
    {
        if (! $actor->hasTeamPermission($company, 'members:scope')) {
            throw new AuthorizationException(__('Bara ägaren och administratörer kan begränsa vad en person når.'));
        }

        // The same answer for an unknown person, the owner and someone outside the company: ids reveal nothing.
        $membership = $memberId === (int) $company->user_id
            ? null
            : CompanyMembership::query()->where('team_id', $company->id)->where('user_id', $memberId)->first();

        if (! $membership instanceof CompanyMembership) {
            throw ValidationException::withMessages(['member' => __('Välj en person i företaget.')]);
        }

        if (! DimensionScope::canBeLimited((string) $membership->getAttribute('role'))) {
            throw ValidationException::withMessages(['member' => __('Administratörer, redovisningskonsulter och revisorer behöver hela bokföringen och kan inte begränsas.')]);
        }

        $costCenterIds = $this->ids($costCenterIds);
        $projectIds = $this->ids($projectIds);

        CurrentCompany::run($company, function () use ($actor, $company, $membership, $memberId, $costCenterIds, $projectIds): void {
            if (CostCenter::query()->whereIn('id', $costCenterIds)->count() !== count($costCenterIds)) {
                throw ValidationException::withMessages(['costCenters' => __('Välj kostnadsställen i företaget.')]);
            }

            if (Project::query()->whereIn('id', $projectIds)->count() !== count($projectIds)) {
                throw ValidationException::withMessages(['projects' => __('Välj projekt i företaget.')]);
            }

            DB::transaction(function () use ($actor, $company, $membership, $memberId, $costCenterIds, $projectIds): void {
                $locked = CompanyMembership::query()->whereKey($membership->getKey())->lockForUpdate()->first();

                if (! $locked instanceof CompanyMembership) {
                    throw ValidationException::withMessages(['member' => __('Välj en person i företaget.')]);
                }

                // The whole earlier scope goes, rows of an earlier membership included: nothing is revived.
                MembershipDimensionScope::query()->where('user_id', $memberId)->delete();

                $limited = $costCenterIds !== [] || $projectIds !== [];

                foreach ([ScopeDimension::CostCenter->value => $costCenterIds, ScopeDimension::Project->value => $projectIds] as $dimension => $ids) {
                    foreach ($ids as $id) {
                        MembershipDimensionScope::create([
                            'user_id' => $memberId,
                            'team_user_id' => (int) $locked->getKey(),
                            'dimension' => $dimension,
                            'object_id' => $id,
                            'created_by' => $actor->id,
                        ]);
                    }
                }

                // Not through the pivot model: Jetstream's Membership has no fillable flag, and this is one column.
                DB::table('team_user')->where('id', $locked->getKey())->update(['dimension_scoped' => $limited]);

                Audit::record($limited ? 'membership.dimension_scope_set' : 'membership.dimension_scope_lifted', null, [
                    'user_id' => $memberId,
                    'role' => (string) $locked->getAttribute('role'),
                    'cost_center_ids' => $costCenterIds,
                    'project_ids' => $projectIds,
                ], $company, $actor);
            });
        });

        DimensionScope::forget();
    }

    /**
     * Lifts a member's limit when their new role can never be limited
     * (UpdateTeamMemberRole), so a former bookkeeper made accountant does not
     * keep a scope that no longer applies.
     */
    public static function liftForRole(Company $company, int $memberId, string $role, ?User $actor): void
    {
        if (DimensionScope::canBeLimited($role)) {
            return;
        }

        $flagged = DB::table('team_user')->where('team_id', $company->id)->where('user_id', $memberId)->where('dimension_scoped', true)->exists();
        // The company is named explicitly; the role change may run outside its context.
        $rows = CompanyScope::bypass(fn () => MembershipDimensionScope::query()->where('company_id', $company->id)->where('user_id', $memberId)->count());

        if (! $flagged && $rows === 0) {
            return;
        }

        CurrentCompany::run($company, function () use ($company, $memberId, $role, $actor): void {
            MembershipDimensionScope::query()->where('user_id', $memberId)->delete();
            DB::table('team_user')->where('team_id', $company->id)->where('user_id', $memberId)->update(['dimension_scoped' => false]);

            Audit::record('membership.dimension_scope_lifted', null, [
                'user_id' => $memberId,
                'role' => $role,
                'reason' => 'role_changed',
            ], $company, $actor);
        });

        DimensionScope::forget();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }
}
