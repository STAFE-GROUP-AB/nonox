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
 * A performed bank reconciliation: on the statement date the bank said
 * statement_balance, the ledger said ledger_balance, and the rows in
 * outstanding_row_ids were booked but not yet on the statement. The
 * difference is what is left unexplained. Append-only: the model refuses
 * changes and a database trigger backs it up.
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property int $account_id
 * @property CarbonImmutable $statement_date
 * @property Money $statement_balance_ore
 * @property Money $ledger_balance_ore
 * @property Money $outstanding_ore
 * @property Money $difference_ore
 * @property list<int> $outstanding_row_ids
 * @property ?string $explanation
 * @property int $reconciled_by
 * @property CarbonImmutable $created_at
 */
class BankReconciliation extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'account_id',
        'statement_date',
        'statement_balance_ore',
        'ledger_balance_ore',
        'outstanding_ore',
        'difference_ore',
        'outstanding_row_ids',
        'explanation',
        'reconciled_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'statement_date' => 'immutable_date',
            'statement_balance_ore' => MoneyCast::class,
            'ledger_balance_ore' => MoneyCast::class,
            'outstanding_ore' => MoneyCast::class,
            'difference_ore' => MoneyCast::class,
            'outstanding_row_ids' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Bank reconciliations cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Bank reconciliations cannot be deleted.');
        });
    }

    public function isBalanced(): bool
    {
        return $this->difference_ore->ore === 0;
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
