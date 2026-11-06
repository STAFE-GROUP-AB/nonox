<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Payables\BankReasonCode;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment in a payment file: an invoice, the amount and the reference
 * the bank reports back (EndToEndId). It gains the supplier payment that
 * settled it when the batch is confirmed or the bank's debit is read, or
 * the bank's status (accepted or rejected, with the reason) while unpaid. A
 * paid or rejected payment never changes again.
 *
 * @property string $batch_id
 * @property string $supplier_invoice_id
 * @property Money $amount_ore
 * @property string $end_to_end_id
 * @property string $currency
 * @property ?int $foreign_amount_minor
 * @property ?string $payment_info_id
 * @property ?int $supplier_payment_id
 * @property ?string $bank_status
 * @property ?CarbonImmutable $bank_status_at
 * @property ?string $reason_code
 * @property ?string $reason_text
 */
class SupplierPaymentBatchItem extends Model
{
    use BelongsToCompany;

    public const string ACCEPTED = 'ACCEPTED';

    public const string REJECTED = 'REJECTED';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'batch_id',
        'supplier_invoice_id',
        'amount_ore',
        'end_to_end_id',
        'currency',
        'foreign_amount_minor',
        'payment_info_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_ore' => MoneyCast::class,
            'bank_status_at' => 'immutable_datetime',
        ];
    }

    /** A payment in a foreign currency: amount_ore is the kronor expected, the amount paid is foreign_amount_minor. */
    public function isForeign(): bool
    {
        return $this->currency !== 'SEK';
    }

    public function isPaid(): bool
    {
        return $this->supplier_payment_id !== null;
    }

    public function isRejected(): bool
    {
        return $this->bank_status === self::REJECTED;
    }

    /** Neither paid nor rejected: the bank may still pay it. */
    public function isPending(): bool
    {
        return ! $this->isPaid() && ! $this->isRejected();
    }

    /** The bank's reason for rejecting the payment, in Swedish. */
    public function rejectionReason(): ?string
    {
        return $this->isRejected() ? BankReasonCode::describe($this->reason_code, $this->reason_text) : null;
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
