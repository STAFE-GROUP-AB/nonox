<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Policies;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Inventory\Models\PurchaseOrder;

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
     * those who manage orders while the order is at or below the company's
     * approval amount (or no amount is set).
     */
    public function send(User $user, PurchaseOrder $order, Company $company): bool
    {
        if ($user->hasTeamPermission($company, InventoryModule::PURCHASE_SEND)) {
            return true;
        }

        if (! $this->manage($user, $company)) {
            return false;
        }

        $limit = InventorySettings::purchaseApprovalAboveOre($company);

        return $limit === null || $order->loadMissing('rows')->totalOre() <= $limit;
    }

    /** Whether sending this order needs someone with purchase-orders:send. */
    public static function needsApproval(PurchaseOrder $order, Company $company): bool
    {
        $limit = InventorySettings::purchaseApprovalAboveOre($company);

        return $limit !== null && $order->loadMissing('rows')->totalOre() > $limit;
    }
}
