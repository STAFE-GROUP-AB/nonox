<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsPurchaseOrders;
use App\Modules\Inventory\Models\PurchaseMatchAllocation;
use App\Modules\Inventory\Models\PurchaseMatchResult;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderInvoice;
use App\Modules\Inventory\Purchasing\Matching\MatchStatus;
use App\Modules\Inventory\Purchasing\Matching\PurchaseMatchAttest;
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
 *
 * Linking and unlinking compare the invoice again row by row (ADR-081 E11);
 * unlinking drops the allocations of the order's rows while the invoice waits
 * for attest, and is refused once the invoice is approved with them, and
 * while the invoice deviates (open or overridden) for anyone who may not
 * attest supplier invoices.
 */
class MatchSupplierInvoice
{
    use GuardsPurchaseOrders;

    public function __construct(private readonly RecomputePurchaseMatch $recompute) {}

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
                ($this->recompute)($company, $invoice, $user);
            }

            return $link;
        }));
    }

    public function unlink(User $user, Company $company, PurchaseOrder $order, SupplierInvoice $invoice): void
    {
        $this->guard($user, $company, 'manage');

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $order, $invoice): void {
            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $allocations = PurchaseMatchAllocation::query()->where('supplier_invoice_id', $invoice->id)
                ->whereIn('purchase_order_row_id', fn ($query) => $query->select('id')->from('purchase_order_rows')->where('purchase_order_id', $order->id));

            if ($invoice->status !== SupplierInvoiceStatus::Registered && $allocations->clone()->exists()) {
                throw ValidationException::withMessages(['purchase_order_id' => __('Fakturan är attesterad med avstämningen mot inköpsordern, så kopplingen kan inte tas bort.')]);
            }

            // Unlinking would make a deviation disappear before the attest: only someone allowed to attest, who did
            // not prepare the comparison under separate approval, may do that. Judged on a fresh comparison.
            $deviations = ($this->recompute)($company, $invoice, $user)->results
                ->contains(fn (PurchaseMatchResult $result): bool => $result->isOpen() || $result->status === MatchStatus::Overridden);

            if ($deviations && ! $user->hasTeamPermission($company, 'supplier-invoices:approve')) {
                throw ValidationException::withMessages(['purchase_order_id' => __('Fakturan avviker från inköpsordern. Bara den som får attestera leverantörsfakturor kan ta bort kopplingen.')]);
            }

            if ($deviations && PurchaseMatchAttest::preparedBy($company, $user, $invoice)) {
                throw ValidationException::withMessages(['purchase_order_id' => __('Fakturan avviker från inköpsordern och du fördelade dess rader, så någon annan med rätt att attestera behöver ta bort kopplingen.')]);
            }

            $cleared = $allocations->delete();
            $deleted = PurchaseOrderInvoice::query()->where('purchase_order_id', $order->id)->where('supplier_invoice_id', $invoice->id)->delete();

            if ($deleted > 0) {
                Audit::record('inventory.purchase_order_invoice_unlinked', $order, ['number' => $order->number, 'supplier_invoice_id' => $invoice->id, 'cleared_allocations' => $cleared], $company, $user);
                ($this->recompute)($company, $invoice, $user);
            }
        }));
    }
}
