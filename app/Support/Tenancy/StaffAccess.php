<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Company;
use App\Models\User;

/**
 * Whether a member works in the company's bookkeeping in any role, as
 * opposed to an employee who only has self-service on Min sida (self:*).
 * Pages such as the chart of accounts, fiscal years, voucher series and
 * company settings are for staff only.
 */
final class StaffAccess
{
    public static function allows(User $user, Company $company): bool
    {
        if (! $user->belongsToTeam($company)) {
            return false;
        }

        return collect($user->teamPermissions($company))->contains(fn (string $permission): bool => ! str_starts_with($permission, 'self:'));
    }
}
