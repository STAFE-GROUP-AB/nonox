<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire\Concerns;

use App\Modules\Inventory\Models\PurchaseOrder;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * What the purchase order pages share on top of every Lager page: the
 * purchase order permissions (PurchaseOrderPolicy).
 *
 * @mixin Component
 */
trait PurchasingPage
{
    use InventoryPage;

    protected function canPurchase(string $ability): bool
    {
        return Gate::forUser($this->user())->allows($ability, [PurchaseOrder::class, $this->company()]);
    }

    protected function canSend(PurchaseOrder $order): bool
    {
        return Gate::forUser($this->user())->allows('send', [$order, $this->company()]);
    }
}
