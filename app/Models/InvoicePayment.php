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
 * A payment received for an invoice, with the voucher that booked it. Never
 * changed; a mistake is corrected through the voucher.
 *
 * @property string $invoice_id
 * @property CarbonImmutable $paid_on
 * @property Money $amount_ore
 * @property int $account_id
 * @property string $voucher_id
 * @property int $created_by
 */
class InvoicePayment extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'invoice_id',
        'paid_on',
        'amount_ore',
        'account_id',
        'voucher_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'paid_on' => 'immutable_date',
            'amount_ore' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        $refuse = function (): never {
            throw new LogicException('Invoice payments cannot be changed or removed.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
