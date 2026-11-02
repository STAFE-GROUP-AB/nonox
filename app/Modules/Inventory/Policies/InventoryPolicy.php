<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Every Lager record: hasTeamPermission checks the membership first, and a
 * module permission is false while the module is off.
 */
class InventoryPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'inventory:view');
    }

    /** Receive, pick, move and count goods; managing the stock includes it. */
    public function count(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'inventory:count') || $user->hasTeamPermission($company, 'inventory:manage');
    }

    /** Articles, locations, costs and the valuation. */
    public function manage(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'inventory:manage');
    }

    public function settings(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'inventory:settings');
    }

    /**
     * Costs and stock values: those who manage the stock, and those who read
     * the company's reports (an auditor). Lagerpersonal never sees them.
     */
    public function seePrices(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'inventory:manage')
            || ($user->hasTeamPermission($company, 'inventory:view') && $user->hasTeamPermission($company, 'reports:view'));
    }

    /** Print labels (ADR-041): handle goods, as for receiving and counting. */
    public function printLabels(User $user, Company $company): bool
    {
        return $this->viewAny($user, $company) && $this->count($user, $company);
    }

    /**
     * Put selling prices on labels: those who manage articles. Lagerpersonal
     * prints article labels without the price, and location labels as usual.
     */
    public function printPrices(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'inventory:manage');
    }
}
