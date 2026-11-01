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
 * A payment made for a supplier invoice, with the voucher that booked it. Never
 * changed; a mistake is corrected through the voucher.
 *
 * @property string $supplier_invoice_id
 * @property CarbonImmutable $paid_on
 * @property Money $amount_ore
 * @property int $account_id
 * @property string $voucher_id
 * @property int $created_by
 * @property ?int $foreign_amount_minor
 * @property int $exchange_difference_ore
 */
class SupplierPayment extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'supplier_invoice_id',
        'paid_on',
        'amount_ore',
        'account_id',
        'voucher_id',
        'created_by',
        'foreign_amount_minor',
        'exchange_difference_ore',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'paid_on' => 'immutable_date',
            'amount_ore' => MoneyCast::class,
            'foreign_amount_minor' => 'integer',
            'exchange_difference_ore' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $refuse = function (): never {
            throw new LogicException('Supplier payments cannot be changed or removed.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<SupplierInvoice, $this> */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}
