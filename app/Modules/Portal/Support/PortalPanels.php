<?php

declare(strict_types=1);

namespace App\Modules\Portal\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Portal\PortalModule;

/**
 * Whether the portal's panels show on the company's own pages (the customer
 * card, the invoice): the module on, and the user may see the portal.
 */
final class PortalPanels
{
    public static function showFor(?User $user): bool
    {
        $company = $user?->currentCompany();

        return $user instanceof User && $company instanceof Company
            && app(ModuleManager::class)->isEnabled(PortalModule::KEY, $company)
            && $user->hasTeamPermission($company, 'customer-portal:view');
    }
}
