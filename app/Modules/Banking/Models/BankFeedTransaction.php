<?php

declare(strict_types=1);

namespace App\Modules\Banking\Models;

use App\Models\BankTransaction;
use App\Models\VoucherRow;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A booked transaction fetched from the bank. What the bank said never
 * changes (model and database trigger); it only gains the ledger row a
 * person confirmed it matches, and the incoming payment it fed.
 *
 * @property int $id
 * @property int $company_id
 * @property int $bank_feed_account_id
 * @property string $fingerprint
 * @property ?string $provider_transaction_id
 * @property ?string $entry_reference
 * @property CarbonImmutable $booking_date
 * @property ?CarbonImmutable $value_date
 * @property Money $amount_ore
 * @property string $currency
 * @property ?string $counterparty
 * @property ?string $message
 * @property ?string $reference
 * @property ?int $voucher_row_id
 * @property ?int $matched_by
 * @property ?CarbonImmutable $matched_at
 * @property ?int $bank_transaction_id
 */
class BankFeedTransaction extends Model
{
    use BelongsToCompany;

    private const array MUTABLE = ['voucher_row_id', 'matched_by', 'matched_at', 'bank_transaction_id', 'updated_at'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'bank_feed_account_id',
        'fingerprint',
        'provider_transaction_id',
        'entry_reference',
        'booking_date',
        'value_date',
        'amount_ore',
        'currency',
        'counterparty',
        'message',
        'reference',
        'bank_transaction_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'booking_date' => 'immutable_date',
            'value_date' => 'immutable_date',
            'amount_ore' => MoneyCast::class,
            'matched_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (BankFeedTransaction $transaction): void {
            if (array_diff(array_keys($transaction->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException("Bank feed transaction {$transaction->id} cannot be changed.");
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Bank feed transactions cannot be removed.');
        });
    }

    public function isMatched(): bool
    {
        return $this->voucher_row_id !== null;
    }

    /** @return BelongsTo<BankFeedAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BankFeedAccount::class, 'bank_feed_account_id');
    }

    /** @return BelongsTo<VoucherRow, $this> */
    public function voucherRow(): BelongsTo
    {
        return $this->belongsTo(VoucherRow::class);
    }

    /** @return BelongsTo<BankTransaction, $this> */
    public function incomingPayment(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }
}
