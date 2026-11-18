<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsPurchaseOrders;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Purchasing\PurchaseOrderReceipts;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Leverans bekräftad" on a row without an article (ADR-081 E10): a service or
 * a consumable is received by a tick, with no stock movement and no voucher. A
 * sent order is then partly or fully received like any other, and the match
 * with the supplier invoice counts a confirmed row as delivered. The tick can
 * be taken back until the order is cancelled or changed into a new version; both are audited.
 */
class ConfirmOrderRowDelivery
{
    use GuardsPurchaseOrders;

    public function __invoke(User $user, Company $company, PurchaseOrder $order, int $rowId, bool $confirmed = true): PurchaseOrderRow
    {
        $this->guard($user, $company, 'manage');

        return CurrentCompany::run($company, fn (): PurchaseOrderRow => DB::transaction(function () use ($user, $company, $order, $rowId, $confirmed): PurchaseOrderRow {
            // Receipts first, then the order (PurchaseOrderReceipts).
            PurchaseOrderReceipts::lockOpen($order->id);
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->wasSent() || $order->isDraft() || $order->status === PurchaseOrderStatus::Cancelled) {
                throw ValidationException::withMessages(['order' => __('Bara en skickad inköpsorder som inte är makulerad kan få en leverans bekräftad.')]);
            }

            $row = PurchaseOrderRow::query()->where('purchase_order_id', $order->id)->whereKey($rowId)->firstOrFail();

            if ($row->isStock()) {
                throw ValidationException::withMessages(['order' => __('Raden är en lagerartikel och tas emot i en inleverans.')]);
            }

            if (($row->confirmed_at !== null) === $confirmed) {
                return $row;
            }

            $row->forceFill($confirmed
                ? ['confirmed_at' => now(), 'confirmed_by' => $user->id]
                : ['confirmed_at' => null, 'confirmed_by' => null])->save();

            PurchaseOrderStatuses::refresh($order);
            Audit::record($confirmed ? 'inventory.purchase_order_row_delivery_confirmed' : 'inventory.purchase_order_row_delivery_unconfirmed', $order, ['number' => $order->number, 'version' => $order->version, 'row_id' => $row->id], $company, $user);

            return $row;
        }));
    }
}
