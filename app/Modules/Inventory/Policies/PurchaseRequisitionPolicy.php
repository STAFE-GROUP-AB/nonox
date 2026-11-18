<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Policies;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PurchaseRequisition;

/**
 * Inköpsanmodan (ADR-081 gap 3, E9). hasTeamPermission checks the membership
 * first, and a module permission is false while Lager is off.
 *
 * A requester always sees their own requisitions (purchase-requisitions:create);
 * everyone's need purchase-requisitions:view. A requester changes and withdraws
 * their own; a buyer (purchase-orders:manage) may change, withdraw and convert
 * anyone's. Approving is the approval engine's.
 */
class PurchaseRequisitionPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, InventoryModule::REQUISITION_CREATE)
            || $user->hasTeamPermission($company, InventoryModule::REQUISITION_VIEW);
    }

    /** Sees every requisition, not only their own. */
    public function viewAll(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, InventoryModule::REQUISITION_VIEW);
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, InventoryModule::REQUISITION_CREATE);
    }

    public function view(User $user, PurchaseRequisition $requisition, Company $company): bool
    {
        return $this->viewAll($user, $company)
            || ($requisition->requested_by === $user->id && $this->create($user, $company));
    }

    /** Change, send to approval, take back or cancel: the requester, or a buyer. */
    public function handle(User $user, PurchaseRequisition $requisition, Company $company): bool
    {
        if ($requisition->requested_by === $user->id && $this->create($user, $company)) {
            return true;
        }

        return $this->convert($user, $company) && $this->viewAny($user, $company);
    }

    /** Turn approved rows into a purchase order: a buyer. */
    public function convert(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, InventoryModule::PURCHASE_MANAGE);
    }
}
