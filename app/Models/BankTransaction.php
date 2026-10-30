<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An incoming payment read from a bank file, with the invoice it is matched
 * to and, once booked, the payment that settled it. What the bank said never
 * changes; a database trigger backs this up.
 *
 * @property int $id
 * @property string $bank_file_import_id
 * @property string $reference
 * @property CarbonImmutable $booking_date
 * @property Money $amount_ore
 * @property ?string $ocr
 * @property ?string $message
 * @property ?string $payer_name
 * @property ?string $invoice_id
 * @property ?int $invoice_payment_id
 */
class BankTransaction extends Model
{
    use BelongsToCompany;

    private const array MUTABLE = ['invoice_id', 'invoice_payment_id', 'updated_at'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'bank_file_import_id',
        'reference',
        'booking_date',
        'amount_ore',
        'ocr',
        'message',
        'payer_name',
        'invoice_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'booking_date' => 'immutable_date',
            'amount_ore' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (BankTransaction $transaction): void {
            if (array_diff(array_keys($transaction->getDirty()), self::MUTABLE) !== [] || $transaction->getOriginal('invoice_payment_id') !== null) {
                throw new LogicException("Bank transaction {$transaction->id} cannot be changed.");
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Bank transactions cannot be removed.');
        });
    }

    public function isBooked(): bool
    {
        return $this->invoice_payment_id !== null;
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<InvoicePayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class, 'invoice_payment_id');
    }
}
