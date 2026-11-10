<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsPurchaseOrders;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderInvoice;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Matches a supplier invoice with a purchase order, or undoes the match
 * (ADR-071 §2). The match lets NONOX show the order and its goods receipts
 * beside the invoice and flag differences; it books nothing and approves
 * nothing. The invoice is booked once, as before, and the receipt never
 * books, so the goods are never booked twice.
 */
class MatchSupplierInvoice
{
    use GuardsPurchaseOrders;

    public function link(User $user, Company $company, PurchaseOrder $order, SupplierInvoice $invoice): PurchaseOrderInvoice
    {
        $this->guard($user, $company, 'manage');

        return CurrentCompany::run($company, fn (): PurchaseOrderInvoice => DB::transaction(function () use ($user, $company, $order, $invoice): PurchaseOrderInvoice {
            $order = PurchaseOrder::query()->findOrFail($order->id);
            $invoice = SupplierInvoice::query()->findOrFail($invoice->id);

            if ($order->supplier_id !== $invoice->supplier_id) {
                throw ValidationException::withMessages(['purchase_order_id' => __('Inköpsordern gäller en annan leverantör än fakturan.')]);
            }

            if (! $order->wasSent()) {
                throw ValidationException::withMessages(['purchase_order_id' => __('Inköpsordern har inte skickats.')]);
            }

            $link = PurchaseOrderInvoice::query()->firstOrCreate(
                ['purchase_order_id' => $order->id, 'supplier_invoice_id' => $invoice->id],
                ['linked_by' => $user->id],
            );

            if ($link->wasRecentlyCreated) {
                Audit::record('inventory.purchase_order_invoice_linked', $order, ['number' => $order->number, 'supplier_invoice_id' => $invoice->id], $company, $user);
            }

            return $link;
        }));
    }

    public function unlink(User $user, Company $company, PurchaseOrder $order, SupplierInvoice $invoice): void
    {
        $this->guard($user, $company, 'manage');

        CurrentCompany::run($company, function () use ($user, $company, $order, $invoice): void {
            $deleted = PurchaseOrderInvoice::query()->where('purchase_order_id', $order->id)->where('supplier_invoice_id', $invoice->id)->delete();

            if ($deleted > 0) {
                Audit::record('inventory.purchase_order_invoice_unlinked', $order, ['number' => $order->number, 'supplier_invoice_id' => $invoice->id], $company, $user);
            }
        });
    }
}
