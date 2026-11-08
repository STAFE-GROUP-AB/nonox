<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Policies;

use App\Models\Company;
use App\Models\User;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderAssignment;
use App\Modules\WorkOrders\WorkOrdersModule;

/**
 * Arbetsorder (ADR-074 §1). hasTeamPermission checks the membership first,
 * and a module permission is false while the module is off (except the
 * archive permission work-orders:view). A field worker (role field_worker)
 * sees and reports only on the work orders they are assigned to (O3).
 */
class WorkOrderPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, WorkOrdersModule::VIEW);
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        $company = $user->currentCompany();

        if (! $company instanceof Company || $company->id !== $workOrder->company_id || ! $user->hasTeamPermission($company, WorkOrdersModule::VIEW)) {
            return false;
        }

        return ! self::onlyAssigned($user, $company) || self::assigned($user, $workOrder);
    }

    public function manage(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, WorkOrdersModule::MANAGE);
    }

    public function send(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, WorkOrdersModule::SEND);
    }

    /** Report time and material: a manager on any work order, a reporter on the work orders they are assigned to. */
    public function report(User $user, WorkOrder $workOrder): bool
    {
        $company = $user->currentCompany();

        if (! $company instanceof Company || $company->id !== $workOrder->company_id) {
            return false;
        }

        if ($user->hasTeamPermission($company, WorkOrdersModule::MANAGE)) {
            return true;
        }

        return $user->hasTeamPermission($company, WorkOrdersModule::REPORT) && self::assigned($user, $workOrder);
    }

    /** A field worker sees only assigned work orders (ADR-074 O3). */
    public static function onlyAssigned(User $user, Company $company): bool
    {
        return ! $user->hasTeamPermission($company, WorkOrdersModule::MANAGE)
            && $user->teamRole($company)?->key === WorkOrdersModule::FIELD_WORKER;
    }

    public static function assigned(User $user, WorkOrder $workOrder): bool
    {
        return WorkOrderAssignment::query()->where('work_order_id', $workOrder->id)->where('user_id', $user->id)->exists();
    }
}
