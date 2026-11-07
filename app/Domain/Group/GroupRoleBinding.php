<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Models\Company;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupRole;
use App\Models\User;
use App\Support\Audit\Audit;

/**
 * Keeps group roles bound to the right membership in the parent when its
 * ownership moves (ADR-060, "S2-1.1 as built"). A role is valid only for the
 * membership it was granted under; the owner has no team_user row, so an
 * owner's role is bound to null. On a transfer the previous owner gets a new
 * team_user row and the new owner loses theirs: their roles follow, so
 * neither loses a role, and a stale role (from a membership that has ended)
 * is never revived. Called inside the transfer's transaction.
 */
final class GroupRoleBinding
{
    public static function ownershipTransferred(
        Company $company,
        User $previousOwner,
        int $previousOwnerMembershipId,
        User $newOwner,
        int $newOwnerFormerMembershipId,
    ): void {
        $groups = CorporateGroup::query()->select('id')->where('parent_company_id', $company->id);

        $rebound = CorporateGroupRole::query()->whereIn('corporate_group_id', $groups)
            ->where('user_id', $previousOwner->id)->whereNull('parent_membership_id')
            ->update(['parent_membership_id' => $previousOwnerMembershipId]);

        $toOwnership = CorporateGroupRole::query()->whereIn('corporate_group_id', $groups)
            ->where('user_id', $newOwner->id)->where('parent_membership_id', $newOwnerFormerMembershipId)
            ->update(['parent_membership_id' => null]);

        if ($rebound + $toOwnership === 0) {
            return;
        }

        Audit::record('corporate_group.roles_rebound', $company, [
            'previous_owner_roles' => $rebound,
            'new_owner_roles' => $toOwnership,
        ], $company, $previousOwner);
    }
}
