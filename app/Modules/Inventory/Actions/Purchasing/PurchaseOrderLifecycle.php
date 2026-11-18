<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\SubjectFacts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsPurchaseOrders;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Purchasing\PurchaseOrderApprovals;
use App\Modules\Inventory\Purchasing\PurchaseOrderReceipts;
use App\Modules\Inventory\Purchasing\PurchaseRequisitionLinks;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changing a sent purchase order and cancelling one (ADR-071 §2). A sent
 * order is changed only as a new version: it goes back to a draft with the
 * next version number, the sent versions stay as they were, and it is sent
 * again. Cancelling keeps the number; an order goods have arrived on is
 * not cancelled.
 */
class PurchaseOrderLifecycle
{
    use GuardsPurchaseOrders;

    public function revise(User $user, Company $company, PurchaseOrder $order): PurchaseOrder
    {
        $this->guard($user, $company, 'manage');

        return CurrentCompany::run($company, fn (): PurchaseOrder => DB::transaction(function () use ($user, $company, $order): PurchaseOrder {
            // Receipts first, then the order, as ReceiveStock locks them (PurchaseOrderReceipts).
            PurchaseOrderReceipts::lockOpen($order->id);
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->status->awaitsGoods()) {
                throw ValidationException::withMessages(['order' => __('Bara en skickad order som väntar på varor kan få en ny version.')]);
            }

            $order->update(['status' => PurchaseOrderStatus::Draft, 'version' => $order->version + 1, 'prepared_by' => $user->id]);
            // The next version is approved again: the approval of the sent one stays in its history.
            PurchaseOrderApprovals::startNewRound($order, $user);
            Audit::record('inventory.purchase_order_revised', $order, ['number' => $order->number, 'version' => $order->version], $company, $user);

            return $order;
        }));
    }

    public function cancel(User $user, Company $company, PurchaseOrder $order): PurchaseOrder
    {
        $this->guard($user, $company, 'manage');

        return CurrentCompany::run($company, fn (): PurchaseOrder => DB::transaction(function () use ($user, $company, $order): PurchaseOrder {
            // Receipts first, then the order, as ReceiveStock locks them, so a receipt
            // being received and the order being cancelled never wait on each other.
            PurchaseOrderReceipts::lockOpen($order->id);
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->status->isOpen()) {
                throw ValidationException::withMessages(['order' => __('Inköpsordern är redan mottagen eller makulerad.')]);
            }

            if ($order->rows()->where(fn ($row) => $row->where('received_milli', '>', 0)->orWhereNotNull('confirmed_at'))->exists()) {
                throw ValidationException::withMessages(['order' => __('Varor har tagits emot eller en leverans är bekräftad på ordern, så den kan inte makuleras. Gör en ny version utan det som inte ska levereras.')]);
            }

            // A goods receipt made from the order where nothing has arrived goes with it.
            StockReceipt::query()->where('purchase_order_id', $order->id)->where('status', StockReceipt::OPEN)->update(['status' => StockReceipt::CANCELLED]);

            // An approval still open on a cancelled order ends with it.
            if ($order->isDraft()) {
                app(ApprovalEngine::class)->withdraw(SubjectFacts::forPurchaseOrder($order), $user);
            }

            $order->update(['status' => PurchaseOrderStatus::Cancelled]);
            // What was converted from purchase requisitions can be ordered again (E9).
            PurchaseRequisitionLinks::releaseOrder($order->id);
            Audit::record('inventory.purchase_order_cancelled', $order, ['number' => $order->number, 'version' => $order->version], $company, $user);

            return $order;
        }));
    }
}
