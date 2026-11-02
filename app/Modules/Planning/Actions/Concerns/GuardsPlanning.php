<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use Illuminate\Support\Facades\Gate;

/**
 * Every Planering action starts here: the module must be on for the
 * company (PAYROLL_MODULE.md A.5) and the user must be allowed to plan.
 */
trait GuardsPlanning
{
    private function guard(User $user, Company $company, string $ability = 'manage'): void
    {
        app(ModuleManager::class)->ensureEnabled(PlanningModule::KEY, $company);
        Gate::forUser($user)->authorize($ability, [Shift::class, $company]);
    }
}
