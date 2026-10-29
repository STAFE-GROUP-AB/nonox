<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\FiscalYearStatus;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The ingående balans of one balance-sheet account for a fiscal year, debit
 * positive and credit negative (ADR-008). Unchangeable once the fiscal year
 * is closed; a database trigger backs this up.
 *
 * @property int $fiscal_year_id
 * @property int $account_id
 * @property Money $amount_ore
 */
class OpeningBalance extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'account_id',
        'amount_ore',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_ore' => MoneyCast::class];
    }

    protected static function booted(): void
    {
        $guard = function (OpeningBalance $balance): void {
            $years = array_unique(array_filter([$balance->fiscal_year_id, $balance->getOriginal('fiscal_year_id')]));

            if (FiscalYear::query()->whereIn('id', $years)->where('status', FiscalYearStatus::Closed->value)->exists()) {
                throw new LogicException('Opening balances of a closed fiscal year cannot be changed.');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
