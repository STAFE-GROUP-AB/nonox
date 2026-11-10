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
 * A later purchase added to an existing asset: the acquisition value grew
 * by `amount_ore` on `added_on`, booked by the voucher row or supplier
 * invoice it links to. `booked_through` is the asset's last booked
 * depreciation month when the addition was made; the added amount is
 * written off from a month after it (FixedAsset::additionTerms).
 * Append-only: the model refuses changes and a database trigger backs it up.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fixed_asset_id
 * @property Money $amount_ore
 * @property CarbonImmutable $added_on
 * @property ?string $booked_through
 * @property ?string $voucher_id
 * @property ?int $voucher_row_id
 * @property ?string $supplier_invoice_id
 * @property int $created_by
 */
class FixedAssetAddition extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fixed_asset_id',
        'amount_ore',
        'added_on',
        'booked_through',
        'voucher_id',
        'voucher_row_id',
        'supplier_invoice_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_ore' => MoneyCast::class,
            'added_on' => 'immutable_date',
            'voucher_row_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Fixed asset additions cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Fixed asset additions cannot be deleted.');
        });
    }

    /** @return BelongsTo<FixedAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return BelongsTo<SupplierInvoice, $this> */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}
