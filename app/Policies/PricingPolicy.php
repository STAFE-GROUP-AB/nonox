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
        $company = $this->company($record);

        return $company instanceof Company && $user->hasTeamPermission($company, 'prices:view');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, $this->managePermission());
    }

    public function update(User $user, Model $record): bool
    {
        $company = $this->company($record);

        return $company instanceof Company && $user->hasTeamPermission($company, $this->managePermission());
    }

    private function company(Model $record): ?Company
    {
        return Company::query()->find((int) $record->getAttribute('company_id'));
    }
}
