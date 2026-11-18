<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Budget\BudgetStatus;
use App\Domain\Budget\LockedBudgetVersion;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One month of one account in a budget version, optionally for one cost
 * centre and one project. The amount is signed as in the ledger and in SIE
 * #PBUDGET: debit positive, credit negative (revenue is negative here and
 * shown positive in the UI).
 *
 * @property int $id
 * @property int $company_id
 * @property int $budget_version_id
 * @property int $account_id
 * @property int|null $cost_center_id
 * @property int|null $project_id
 * @property CarbonImmutable $period
 * @property Money $amount_ore
 * @property bool $is_override
 */
class BudgetLine extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'budget_version_id',
        'account_id',
        'cost_center_id',
        'project_id',
        'period',
        'amount_ore',
        'is_override',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_override' => false,
    ];

    protected static function booted(): void
    {
        $guard = function (BudgetLine $line): void {
            $status = BudgetVersion::query()->whereKey($line->getOriginal('budget_version_id') ?? $line->budget_version_id)->toBase()->value('status');

            if ($status === BudgetStatus::Locked->value) {
                throw LockedBudgetVersion::make();
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period' => 'immutable_date',
            'amount_ore' => MoneyCast::class,
            'is_override' => 'boolean',
        ];
    }

    /** @return BelongsTo<BudgetVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class, 'budget_version_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
