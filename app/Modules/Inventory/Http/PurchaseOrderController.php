<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderVersion;
use App\Modules\Inventory\Purchasing\PurchaseOrderDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The purchase order's pages and its PDF (ADR-071 §2). Another company's id
 * answers 404 (the company scope).
 */
class PurchaseOrderController
{
    public function create(): View
    {
        Gate::authorize('manage', [PurchaseOrder::class, $this->company()]);

        return view('inventory::purchase-order-edit', ['order' => null]);
    }

    public function show(int $purchaseOrder): View
    {
        $order = PurchaseOrder::query()->findOrFail($purchaseOrder);
        Gate::authorize('viewAny', [PurchaseOrder::class, $this->company()]);

        return view('inventory::purchase-order', ['order' => $order]);
    }

    public function edit(int $purchaseOrder): View|RedirectResponse
    {
        $order = PurchaseOrder::query()->findOrFail($purchaseOrder);
        Gate::authorize('manage', [PurchaseOrder::class, $this->company()]);

        if (! $order->isDraft()) {
            return redirect()->route('inventory.purchaseOrders.show', $order->id);
        }

        return view('inventory::purchase-order-edit', ['order' => $order]);
    }

    /** The current draft as the supplier would get it, or a sent version (?version=2). */
    public function pdf(Request $request, int $purchaseOrder): Response
    {
        $order = PurchaseOrder::query()->findOrFail($purchaseOrder);
        Gate::authorize('viewAny', [PurchaseOrder::class, $this->company()]);

        $version = $request->query('version');
        $snapshot = is_numeric($version)
            ? PurchaseOrderVersion::query()->where('purchase_order_id', $order->id)->where('version', (int) $version)->firstOrFail()->snapshot
            : PurchaseOrderDocument::snapshot($order);

        return response(PurchaseOrderDocument::render($this->company(), $snapshot), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.PurchaseOrderDocument::filename($snapshot).'"',
        ]);
    }

    private function company(): Company
    {
        /** @var User $user */
        $user = Auth::user();

        /** @var Company $company */
        $company = $user->currentCompany();

        return $company;
    }
}
