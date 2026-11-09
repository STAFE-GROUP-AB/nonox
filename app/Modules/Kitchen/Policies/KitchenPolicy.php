<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Policies;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;

/**
 * Every Kök record (ADR-073 §11): hasTeamPermission checks the membership
 * first, and a module permission is false while the module is off.
 */
class KitchenPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'kitchen:view');
    }

    /** Register waste and staff meals, on the page and the phone. */
    public function register(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'kitchen:register');
    }

    /** Recipes, dishes, costs and margins, the variance report and the settings. */
    public function manage(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'kitchen:manage');
    }

    /**
     * Costs and margins: those who manage the kitchen, and those who read
     * the company's reports (an auditor). Kökspersonal never sees them.
     */
    public function seeCosts(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'kitchen:manage')
            || ($user->hasTeamPermission($company, 'kitchen:view') && $user->hasTeamPermission($company, 'reports:view'));
    }

    /**
     * Staff meals per employee and the hand-over to payroll: payroll must be
     * on, and the user must both see the kitchen and manage payroll.
     */
    public function payroll(User $user, Company $company): bool
    {
        return app(ModuleManager::class)->isEnabled(PayrollModule::KEY, $company)
            && $user->hasTeamPermission($company, 'kitchen:view')
            && $user->hasTeamPermission($company, 'payroll:manage');
    }
}
