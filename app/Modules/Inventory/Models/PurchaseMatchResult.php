<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\SupplierInvoice;
use App\Models\User;
use App\Modules\Inventory\Purchasing\Matching\MatchReason;
use App\Modules\Inventory\Purchasing\Matching\MatchStatus;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The stored three-way comparison of one purchase order row with its goods
 * receipts and the supplier invoice rows allocated to it, or of one invoice
 * row without an order row (ADR-081 E11). Quantities are thousandths of the
 * order row's unit, amounts öre, tolerances the ones it was judged by.
 *
 * A result is never deleted or edited. A new comparison supersedes the set
 * (superseded_at, once), and an outside or unmatched result may be overridden
 * once with a reason; a database trigger enforces both.
 *
 * @property int $id
 * @property int $company_id
 * @property string $supplier_invoice_id
 * @property ?int $supplier_invoice_row_id
 * @property list<int> $invoice_row_ids
 * @property ?int $purchase_order_row_id
 * @property ?int $stock_receipt_line_id
 * @property int $ordered_milli
 * @property int $received_milli
 * @property int $previously_invoiced_milli
 * @property int $invoiced_milli
 * @property int $ordered_ore
 * @property int $received_ore
 * @property int $invoiced_ore
 * @property int $expected_ore
 * @property int $quantity_diff_milli
 * @property int $price_diff_ore
 * @property int $quantity_tolerance_bp
 * @property int $price_tolerance_bp
 * @property int $absolute_tolerance_ore
 * @property MatchStatus $status
 * @property list<string> $reasons
 * @property string $fingerprint
 * @property ?string $override_reason
 * @property ?int $overridden_by
 * @property ?CarbonImmutable $overridden_at
 * @property ?int $carried_from_id
 * @property CarbonImmutable $computed_at
 * @property ?int $computed_by
 * @property ?CarbonImmutable $superseded_at
 */
class PurchaseMatchResult extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'supplier_invoice_id',
        'supplier_invoice_row_id',
        'invoice_row_ids',
        'purchase_order_row_id',
        'stock_receipt_line_id',
        'ordered_milli',
        'received_milli',
        'previously_invoiced_milli',
        'invoiced_milli',
        'ordered_ore',
        'received_ore',
        'invoiced_ore',
        'expected_ore',
        'quantity_diff_milli',
        'price_diff_ore',
        'quantity_tolerance_bp',
        'price_tolerance_bp',
        'absolute_tolerance_ore',
        'status',
        'reasons',
        'fingerprint',
        'override_reason',
        'overridden_by',
        'overridden_at',
        'carried_from_id',
        'computed_at',
        'computed_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'invoice_row_ids' => 'array',
            'reasons' => 'array',
            'status' => MatchStatus::class,
            'purchase_order_row_id' => 'integer',
            'supplier_invoice_row_id' => 'integer',
            'stock_receipt_line_id' => 'integer',
            'ordered_milli' => 'integer',
            'received_milli' => 'integer',
            'previously_invoiced_milli' => 'integer',
            'invoiced_milli' => 'integer',
            'ordered_ore' => 'integer',
            'received_ore' => 'integer',
            'invoiced_ore' => 'integer',
            'expected_ore' => 'integer',
            'quantity_diff_milli' => 'integer',
            'price_diff_ore' => 'integer',
            'quantity_tolerance_bp' => 'integer',
            'price_tolerance_bp' => 'integer',
            'absolute_tolerance_ore' => 'integer',
            'overridden_by' => 'integer',
            'carried_from_id' => 'integer',
            'computed_by' => 'integer',
            'overridden_at' => 'immutable_datetime',
            'computed_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (PurchaseMatchResult $result): void {
            throw new LogicException("Purchase match result {$result->id} cannot be deleted.");
        });
    }

    /** Whether the result still waits for a person: outside the tolerances or without an order row, and not overridden. */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** @return list<MatchReason> */
    public function reasonCases(): array
    {
        return array_values(array_filter(array_map(fn (string $reason): ?MatchReason => MatchReason::tryFrom($reason), $this->reasons)));
    }

    /** @return BelongsTo<SupplierInvoice, $this> */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    /** @return BelongsTo<PurchaseOrderRow, $this> */
    public function purchaseOrderRow(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderRow::class);
    }

    /** @return BelongsTo<User, $this> */
    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }
}
