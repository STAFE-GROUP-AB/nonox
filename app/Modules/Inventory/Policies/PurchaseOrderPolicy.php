<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Policies;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\PurchaseOrderApprovalMode;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\PurchaseOrderApprovals;

/**
 * Inköpsorder (ADR-071 §1, §2). hasTeamPermission checks the membership
 * first, and a module permission is false while Lager is off.
 */
class PurchaseOrderPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, InventoryModule::PURCHASE_VIEW);
    }

    /** Write, change, cancel and match orders, and create them from the proposal. */
    public function manage(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, InventoryModule::PURCHASE_MANAGE);
    }

    /**
     * Send an order to the supplier: anyone with purchase-orders:send, and
     * those who manage orders unless the order is above the company's approval
     * amount with no approval policy of its own (the default policy). An order
     * a policy applies to must also have been approved through it; the action
     * that sends it checks that (ADR-081 E10).
     */
    public function send(User $user, PurchaseOrder $order, Company $company): bool
    {
        if ($user->hasTeamPermission($company, InventoryModule::PURCHASE_SEND)) {
            return true;
        }

        if (! $this->manage($user, $company)) {
            return false;
        }

        return PurchaseOrderApprovals::mode($order, $company) !== PurchaseOrderApprovalMode::Threshold;
    }

    /** Whether sending this order needs someone with purchase-orders:send (the threshold, with no policy of its own). */
    public static function needsApproval(PurchaseOrder $order, Company $company): bool
    {
        return PurchaseOrderApprovals::mode($order, $company) === PurchaseOrderApprovalMode::Threshold;
    }
}
