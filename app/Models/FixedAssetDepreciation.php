<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One booked depreciation month for an asset, part of a posted voucher.
 * Append-only: the model refuses changes and a database trigger backs it up.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fixed_asset_id
 * @property string $period
 * @property Money $amount_ore
 * @property string $voucher_id
 */
class FixedAssetDepreciation extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fixed_asset_id',
        'period',
        'amount_ore',
        'voucher_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_ore' => MoneyCast::class];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Booked depreciations cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Booked depreciations cannot be deleted.');
        });
    }
}
