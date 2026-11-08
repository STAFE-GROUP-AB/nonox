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
 * valued at the closing rate, ADR-025) or UNPAID_RECEIVABLES and
 * UNPAID_PAYABLES (under kontantmetoden, the invoices unpaid on the last day,
 * booked then and reversed on the first day of the next year) or
 * STOCK_VALUATION (the stock change from Lager's year-end valuation) or
 * DEFERRED_TAX (a K3 company's change of deferred tax). Append-only: the model refuses changes and a database trigger
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

    /** Kontantmetoden: customer invoices unpaid on the last day; amount_ore is the receivable booked on 1510. */
    public const string UNPAID_RECEIVABLES = 'UNPAID_RECEIVABLES';

    /** Kontantmetoden: supplier invoices unpaid on the last day; amount_ore is the liability booked on 2440. */
    public const string UNPAID_PAYABLES = 'UNPAID_PAYABLES';

    /** Lager (ADR-039): the stock valued at the year end; amount_ore is the change booked on the stock account. */
    public const string STOCK_VALUATION = 'STOCK_VALUATION';

    /** K3 (ADR-059, A.2.6): the change of deferred tax booked on 8940; amount_ore is the cost (an income negative). */
    public const string DEFERRED_TAX = 'DEFERRED_TAX';

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
