<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Prices (ADR-082 §Permissions): everything about prices is seen with
 * prices:view; who may change it depends on the register (price lists and
 * groups, or price agreements). The record's own company decides, so a
 * record of another company is never allowed.
 */
abstract class PricingPolicy
{
    /** The permission that changes this register. */
    abstract protected function managePermission(): string;

    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'prices:view');
    }

    public function view(User $user, Model $record): bool
    {
        $company = $this->company($user, $record);

        return $company instanceof Company && $user->hasTeamPermission($company, 'prices:view');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, $this->managePermission());
    }

    public function update(User $user, Model $record): bool
    {
        $company = $this->company($user, $record);

        return $company instanceof Company && $user->hasTeamPermission($company, $this->managePermission());
    }

    /**
     * The record's company. In the user's current company (every list page)
     * that is the user's already loaded current team, so checking a whole
     * list costs no query per row; only a record elsewhere is looked up.
     */
    private function company(User $user, Model $record): ?Company
    {
        $companyId = (int) $record->getAttribute('company_id');

        if ($user->current_team_id !== null && (int) $user->current_team_id === $companyId) {
            $current = $user->currentTeam;

            if ($current instanceof Company) {
                return $current;
            }
        }

        return Company::query()->find($companyId);
    }
}
