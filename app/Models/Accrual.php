<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accruals\AccrualKind;
use App\Domain\Accruals\AccrualPlan;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A periodisering: an amount between a balance account (1790, 2990) and a
 * result account, spread over months or accrued for one month and reversed.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property string $name
 * @property AccrualKind $kind
 * @property Money $total_ore
 * @property int $balance_account
 * @property int $result_account
 * @property string $start_month
 * @property int $months
 * @property bool $move_from_result
 * @property ?int $cost_center_id
 * @property ?string $notes
 * @property int $created_by
 */
class Accrual extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'name',
        'kind',
        'total_ore',
        'balance_account',
        'result_account',
        'start_month',
        'months',
        'move_from_result',
        'cost_center_id',
        'notes',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => AccrualKind::class,
            'total_ore' => MoneyCast::class,
            'balance_account' => 'integer',
            'result_account' => 'integer',
            'months' => 'integer',
            'move_from_result' => 'boolean',
        ];
    }

    public function plan(): AccrualPlan
    {
        return new AccrualPlan($this->total_ore->ore, $this->kind->isSpread() ? $this->months : 1, $this->start_month);
    }

    /** The booked amount of a type; uses the loaded postings when there are any (with('postings')). */
    public function bookedOre(string $type): int
    {
        return (int) $this->postings->where('type', $type)->sum(fn (AccrualPosting $posting): int => $posting->amount_ore->ore);
    }

    public function hasBooked(string $type): bool
    {
        return $this->postings->contains('type', $type);
    }

    /** What is left on the balance account for this accrual. */
    public function remainingOre(): int
    {
        if ($this->kind->isSpread()) {
            return $this->total_ore->ore - $this->bookedOre(AccrualPosting::RELEASE);
        }

        return $this->bookedOre(AccrualPosting::ACCRUAL) - $this->bookedOre(AccrualPosting::REVERSAL);
    }

    public function isDone(): bool
    {
        if ($this->kind->isSpread()) {
            return $this->remainingOre() <= 0;
        }

        return $this->hasBooked(AccrualPosting::REVERSAL);
    }

    /** @return HasMany<AccrualPosting, $this> */
    public function postings(): HasMany
    {
        return $this->hasMany(AccrualPosting::class);
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
