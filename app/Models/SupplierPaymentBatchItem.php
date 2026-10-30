<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment in a payment file: an invoice, the amount and the reference
 * the bank reports back (EndToEndId). It gains the supplier payment that
 * settled it when the batch is confirmed.
 *
 * @property string $batch_id
 * @property string $supplier_invoice_id
 * @property Money $amount_ore
 * @property string $end_to_end_id
 * @property ?int $supplier_payment_id
 */
class SupplierPaymentBatchItem extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'batch_id',
        'supplier_invoice_id',
        'amount_ore',
        'end_to_end_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_ore' => MoneyCast::class,
        ];
    }

    /** @return BelongsTo<SupplierInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    /** @return BelongsTo<SupplierPaymentBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SupplierPaymentBatch::class, 'batch_id');
    }
}
