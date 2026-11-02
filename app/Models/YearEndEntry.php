<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A closing entry of a fiscal year: TAX (the year's income tax, possibly
 * zero and then without a voucher), RESULT (the year's result moved to
 * equity) or DISPOSITION (the result disposed of in the next year, with the
 * dividend) or CURRENCY_REVALUATION (open invoices in another currency
 * valued at the closing rate, ADR-025). Append-only: the model refuses changes and a database trigger
 * backs it up.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property string $type
 * @property Money $amount_ore
 * @property Money $dividend_ore
 * @property ?string $voucher_id
 * @property int $created_by
 */
class YearEndEntry extends Model
{
    use BelongsToCompany;

    public const string TAX = 'TAX';

    public const string RESULT = 'RESULT';

    public const string DISPOSITION = 'DISPOSITION';

    /** Invoices in another currency valued at the closing rate; amount_ore is the net gain (negative a loss). */
    public const string CURRENCY_REVALUATION = 'CURRENCY_REVALUATION';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'type',
        'amount_ore',
        'dividend_ore',
        'voucher_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_ore' => MoneyCast::class,
            'dividend_ore' => MoneyCast::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Year-end entries cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Year-end entries cannot be deleted.');
        });
    }
}
