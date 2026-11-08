<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\WorkOrdersModule;
use Illuminate\Support\Facades\Gate;

/**
 * Every Arbetsorder action starts here: the module must be on for the
 * company and the user allowed.
 */
trait GuardsWorkOrders
{
    private function guard(User $user, Company $company, string $ability = 'manage'): void
    {
        app(ModuleManager::class)->ensureEnabled(WorkOrdersModule::KEY, $company);
        Gate::forUser($user)->authorize($ability, [WorkOrder::class, $company]);
    }

    private function guardReport(User $user, Company $company, WorkOrder $workOrder): void
    {
        app(ModuleManager::class)->ensureEnabled(WorkOrdersModule::KEY, $company);
        Gate::forUser($user)->authorize('report', $workOrder);
    }
}
