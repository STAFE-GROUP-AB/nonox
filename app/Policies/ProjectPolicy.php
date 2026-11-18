<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\DimensionScope;
use Illuminate\Auth\Access\Response;

/**
 * Projects as a dimension (ADR-009) and as a register (ADR-081 gap 6):
 * `projects:view` sees the register, `projects:manage` keeps it; those who
 * see vouchers or manage accounts keep doing so as before. The project
 * results (E7) need `projects:view`; a member limited to cost centres or
 * projects (gap 10) sees only the results of their own projects.
 */
class ProjectPolicy extends DimensionObjectPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return parent::viewAny($user, $company) || $user->hasTeamPermission($company, 'projects:view');
    }

    public function create(User $user, Company $company): bool
    {
        return parent::create($user, $company) || $user->hasTeamPermission($company, 'projects:manage');
    }

    public function update(User $user, CostCenter|Project $object): bool
    {
        if (parent::update($user, $object)) {
            return true;
        }

        $company = Company::query()->find($object->company_id);

        return $company instanceof Company && $user->hasTeamPermission($company, 'projects:manage');
    }

    /** The project results list (ADR-081 E7). */
    public function viewResults(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'projects:view');
    }

    /**
     * One project's result: `projects:view`, and for a limited member only a
     * project in their scope. Outside it the project answers 404, as another
     * company's would (ADR-081 gap 10).
     */
    public function viewResult(User $user, Project $project): Response|bool
    {
        $company = Company::query()->find($project->company_id);

        if (! $company instanceof Company) {
            return false;
        }

        if (! $user->hasTeamPermission($company, 'projects:view')) {
            return DimensionScope::limits($user, $company) ? Response::denyAsNotFound() : false;
        }

        return DimensionScope::for($user, $company)->allowsProject($project->id) ? true : Response::denyAsNotFound();
    }
}
