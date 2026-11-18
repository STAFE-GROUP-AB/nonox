<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Approval policies, dimension owners and other people's substitutes are
 * managed with approvals:manage (owner, administrator; ADR-081 gap 8). Every
 * member may read the policies and set their own substitute.
 */
class ApprovalPolicyPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->belongsToTeam($company);
    }

    public function manage(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'approvals:manage');
    }
}
