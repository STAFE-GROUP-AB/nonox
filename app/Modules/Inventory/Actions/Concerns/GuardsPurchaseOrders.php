<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Concerns;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\Gate;

/**
 * Every purchase order action starts here: Lager must be on for the company
 * and the user must hold the ability (PurchaseOrderPolicy).
 */
trait GuardsPurchaseOrders
{
    private function guard(User $user, Company $company, string $ability): void
    {
        app(ModuleManager::class)->ensureEnabled(InventoryModule::KEY, $company);
        Gate::forUser($user)->authorize($ability, [PurchaseOrder::class, $company]);
    }
}
