<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Domain\Projects\MilestoneStatus;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Removes a milestone from a project's billing plan (ADR-081 E8), only while
 * it is not invoiced and only the version the person saw.
 */
class DeleteBillingMilestone
{
    public function __invoke(User $user, Company $company, ProjectBillingMilestone $milestone, string $token): void
    {
        $project = CurrentCompany::run($company, fn (): Project => Project::query()->findOrFail($milestone->project_id));
        Gate::forUser($user)->authorize('manageBillingPlan', $project);

        DB::transaction(fn () => CurrentCompany::run($company, function () use ($user, $company, $milestone, $token): void {
            $current = ProjectBillingMilestone::query()->whereKey($milestone->id)->lockForUpdate()->firstOrFail();

            if (! hash_equals($current->versionToken(), $token)) {
                throw ValidationException::withMessages(['milestone' => __('Delfaktureringen har ändrats sedan du öppnade sidan. Granska den igen.')]);
            }

            if ($current->effectiveStatus() === MilestoneStatus::Invoiced) {
                throw ValidationException::withMessages(['milestone' => __('Delfaktureringen är redan fakturerad och kan inte tas bort.')]);
            }

            $current->delete();
            Audit::record('projects.milestone_deleted', $current, ['project_id' => $current->project_id], $company, $user);
        }));
    }
}
