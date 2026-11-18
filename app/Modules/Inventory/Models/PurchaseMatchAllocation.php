<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which purchase order row a row of a registered supplier invoice pays for,
 * and how much of it the invoice charges for, in thousandths of the order
 * row's own unit (ADR-081 E11).
 *
 * @property int $id
 * @property int $company_id
 * @property string $supplier_invoice_id
 * @property int $supplier_invoice_row_id
 * @property int $purchase_order_row_id
 * @property int $invoiced_milli
 * @property int $allocated_by
 */
class PurchaseMatchAllocation extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'supplier_invoice_id',
        'supplier_invoice_row_id',
        'purchase_order_row_id',
        'invoiced_milli',
        'allocated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supplier_invoice_row_id' => 'integer',
            'purchase_order_row_id' => 'integer',
            'invoiced_milli' => 'integer',
            'allocated_by' => 'integer',
        ];
    }

    /** @return BelongsTo<SupplierInvoice, $this> */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    /** @return BelongsTo<SupplierInvoiceRow, $this> */
    public function supplierInvoiceRow(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoiceRow::class);
    }

    /** @return BelongsTo<PurchaseOrderRow, $this> */
    public function purchaseOrderRow(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderRow::class);
    }
}
