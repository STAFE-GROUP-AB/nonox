<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\SupplierInvoice;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier invoice matched with a purchase order by a person (ADR-071
 * §2). The link only lets NONOX show the two side by side and flag
 * differences; the invoice is booked and approved as before.
 *
 * @property int $id
 * @property int $company_id
 * @property int $purchase_order_id
 * @property string $supplier_invoice_id
 * @property ?int $linked_by
 */
class PurchaseOrderInvoice extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'purchase_order_id', 'supplier_invoice_id', 'linked_by'];

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<SupplierInvoice, $this> */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}
