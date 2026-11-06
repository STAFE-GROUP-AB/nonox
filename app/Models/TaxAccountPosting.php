<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A transaction on the tax account at Skatteverket that a person booked in
 * NONOX (ADR-054 stage 3). One row per transaction and company, so it is
 * never booked twice. Only ids, the date, the amount and the account. The
 * voucher points back here through vouchers.source_type and source_id.
 *
 * A row is never changed or removed (model events and a PostgreSQL trigger).
 * When its voucher has been corrected the row is marked superseded, once, and
 * the transaction may be booked again; one row per transaction is in force.
 *
 * @property int $id
 * @property int $company_id
 * @property int $transaction_id
 * @property CarbonImmutable $transaction_date
 * @property Money $amount_ore
 * @property int $account_number
 * @property ?int $confirmed_by
 * @property ?CarbonImmutable $superseded_at
 */
class TaxAccountPosting extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'transaction_id',
        'transaction_date',
        'amount_ore',
        'account_number',
        'confirmed_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'transaction_id' => 'integer',
            'transaction_date' => 'immutable_date',
            'amount_ore' => MoneyCast::class,
            'account_number' => 'integer',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $posting): void {
            $changes = array_diff(array_keys($posting->getDirty()), ['superseded_at', 'updated_at']);

            if ($changes !== [] || $posting->getOriginal('superseded_at') !== null || $posting->superseded_at === null) {
                throw new LogicException('A booked tax account transaction cannot be changed.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A booked tax account transaction cannot be deleted.');
        });
    }

    /** Marks the row superseded because its voucher has been corrected; only once. */
    public function markSuperseded(): void
    {
        if ($this->superseded_at !== null) {
            throw new LogicException('The booked tax account transaction is already superseded.');
        }

        $this->forceFill(['superseded_at' => now()])->save();
    }
}
