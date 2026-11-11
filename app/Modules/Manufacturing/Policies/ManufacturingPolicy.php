<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Every Tillverkning record (ADR-071 §1): hasTeamPermission checks the
 * membership first, and a module permission is false while the module is
 * off.
 */
class ManufacturingPolicy
{
    /** Bills of materials, operations and the costing on quotes and orders. */
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'manufacturing:view');
    }

    /** Bills of materials, operations and the settings. */
    public function manage(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'manufacturing:manage');
    }

    /** Setting up the workshop terminal: tablets and the people on it (ADR-071 §7). */
    public function terminal(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'production:report');
    }

    /** The post-calculation per product and operation (ADR-071 §7). */
    public function analyse(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'production:analyse');
    }
}
