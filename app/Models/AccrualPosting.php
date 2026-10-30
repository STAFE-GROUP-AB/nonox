<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One booked step of an accrual, part of a posted voucher: OPENING moves a
 * prepaid amount from the result account to the balance account, RELEASE
 * moves a month's share back, ACCRUAL books an accrued amount at a month's
 * end and REVERSAL takes it back the next month. Append-only: the model
 * refuses changes and a database trigger backs it up.
 *
 * @property int $id
 * @property int $company_id
 * @property int $accrual_id
 * @property string $period
 * @property string $type
 * @property Money $amount_ore
 * @property string $voucher_id
 */
class AccrualPosting extends Model
{
    use BelongsToCompany;

    public const string OPENING = 'OPENING';

    public const string RELEASE = 'RELEASE';

    public const string ACCRUAL = 'ACCRUAL';

    public const string REVERSAL = 'REVERSAL';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'accrual_id',
        'period',
        'type',
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
            throw new LogicException('Booked accruals cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Booked accruals cannot be deleted.');
        });
    }
}
