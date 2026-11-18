<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Analysis\DatasetCatalogue;
use App\Domain\Analysis\Reports\ReportVisibility;
use App\Models\Company;
use App\Models\ReportDefinition;
use App\Models\User;

/**
 * Saved reports (ADR-081 gap 7, E18). A private report is its owner's alone; a
 * company report is seen by everyone who may use the analysis view, and what
 * it shows each of them is decided when it is run (the viewer's own dataset
 * permission and dimension scope), not here. Making a report visible to the
 * company needs analysis:share; changing one is the owner's, and deleting a
 * company report is also open to whoever holds analysis:share. The owner of a report may leave: their private reports are deleted, the company ones stay without an owner and are then looked after by whoever holds analysis:share.
 */
class ReportDefinitionPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'analysis:view');
    }

    public function view(User $user, ReportDefinition $report): bool
    {
        $company = $this->company($report);

        if (! $company instanceof Company || ! $user->hasTeamPermission($company, 'analysis:view')) {
            return false;
        }

        if (! isset(DatasetCatalogue::for($user, $company)[$report->dataset])) {
            return false;
        }

        return $report->owner_user_id === $user->id || $report->visibility === ReportVisibility::Company;
    }

    public function create(User $user, Company $company, ReportVisibility $visibility = ReportVisibility::Private): bool
    {
        if (! $user->hasTeamPermission($company, 'analysis:view')) {
            return false;
        }

        return $visibility === ReportVisibility::Private || $user->hasTeamPermission($company, 'analysis:share');
    }

    /** Changing a report is the owner's; a company report whose owner has left can be changed by whoever may share. */
    public function update(User $user, ReportDefinition $report): bool
    {
        return $this->view($user, $report) && ($report->owner_user_id === $user->id || $this->ownerlessCompanyReport($user, $report));
    }

    /**
     * Pinning or unpinning. The owner pins their own (a company report needs
     * analysis:share, as it puts a tile on everyone's overview). Someone else
     * with analysis:share may unpin any company report, and pin one whose
     * owner has left.
     */
    public function pin(User $user, ReportDefinition $report, bool $pinned = true): bool
    {
        $company = $this->company($report);

        if (! $this->view($user, $report) || ! $company instanceof Company) {
            return false;
        }

        $shares = $user->hasTeamPermission($company, 'analysis:share');

        if ($report->owner_user_id === $user->id) {
            return ! $pinned || $report->visibility === ReportVisibility::Private || $shares;
        }

        return $report->visibility === ReportVisibility::Company && $shares && (! $pinned || $report->owner_user_id === null);
    }

    public function delete(User $user, ReportDefinition $report): bool
    {
        $company = $this->company($report);

        if (! $company instanceof Company || ! $this->view($user, $report)) {
            return false;
        }

        return $report->owner_user_id === $user->id
            || ($report->visibility === ReportVisibility::Company && $user->hasTeamPermission($company, 'analysis:share'));
    }

    private function ownerlessCompanyReport(User $user, ReportDefinition $report): bool
    {
        $company = $this->company($report);

        return $report->owner_user_id === null
            && $report->visibility === ReportVisibility::Company
            && $company instanceof Company
            && $user->hasTeamPermission($company, 'analysis:share');
    }

    private function company(ReportDefinition $report): ?Company
    {
        return Company::query()->find($report->company_id);
    }
}
