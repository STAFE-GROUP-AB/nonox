<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Every check requires membership in the company and the permission from
 * AGENTS.md §4. `hasTeamPermission` covers both: it is false for non-members
 * and true for the owner.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Company $company): bool
    {
        return $user->belongsToTeam($company);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'company:update');
    }

    public function addTeamMember(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'members:manage');
    }

    public function updateTeamMember(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'members:manage');
    }

    public function removeTeamMember(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'members:manage');
    }

    /**
     * Companies are archived, never deleted (AGENTS.md §3.5).
     */
    public function delete(User $user, Company $company): bool
    {
        return false;
    }

    /**
     * Owner only (AGENTS.md §4): `company:archive` is not given to any member
     * role. An archived company is read-only (ADR-024).
     */
    public function archive(User $user, Company $company): bool
    {
        return ! $company->isArchived() && $user->hasTeamPermission($company, 'company:archive');
    }

    public function unarchive(User $user, Company $company): bool
    {
        return $company->isArchived() && $user->hasTeamPermission($company, 'company:archive');
    }

    /**
     * Only the owner hands the company over (AGENTS.md §4), also while it is
     * archived: the ownership is not räkenskapsinformation, and an owner
     * leaving an archived company needs someone to take it over.
     */
    public function transferOwnership(User $user, Company $company): bool
    {
        return $user->ownsTeam($company);
    }
}
