<?php

declare(strict_types=1);

namespace App\Modules\Crm\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Stage 5 (ADR-048): e-mail, templates, segments, mailings and forms.
 *
 * - crm:manage logs e-mail, handles "Ej kopplade" and sends a template to
 *   one person (a seller's everyday work);
 * - crm:settings writes templates, segments and web forms;
 * - crm:mailings composes and sends mailings to segments: mass marketing
 *   in the company's name, so it is its own permission, held by owner and
 *   admin, and given to anyone else by changing their role.
 *
 * hasTeamPermission checks the membership first, and a module permission
 * is false while the module is off.
 */
class CrmMailPolicy
{
    public function manage(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'crm:manage');
    }

    public function settings(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'crm:settings');
    }

    public function mailings(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'crm:mailings');
    }
}
