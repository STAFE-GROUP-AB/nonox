<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Assets\AssetCategory;
use App\Domain\Assets\DepreciationPlan;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fixed asset in the anläggningsregister with its linear depreciation plan.
 * Its acquisition was booked when it was bought; the register books the
 * depreciation and, at the end, its disposal.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property string $name
 * @property AssetCategory $category
 * @property CarbonImmutable $acquired_on
 * @property Money $acquisition_ore
 * @property Money $residual_ore
 * @property Money $opening_accumulated_ore
 * @property int $useful_life_months
 * @property CarbonImmutable $depreciation_start
 * @property int $asset_account
 * @property int $accumulated_account
 * @property int $expense_account
 * @property ?int $cost_center_id
 * @property string $status
 * @property ?CarbonImmutable $disposed_on
 * @property ?Money $disposal_price_ore
 * @property ?string $disposal_voucher_id
 * @property ?string $notes
 * @property int $created_by
 */
class FixedAsset extends Model
{
    use BelongsToCompany;

    public const string ACTIVE = 'ACTIVE';

    public const string DISPOSED = 'DISPOSED';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'number',
        'name',
        'category',
        'acquired_on',
        'acquisition_ore',
        'residual_ore',
        'opening_accumulated_ore',
        'useful_life_months',
        'depreciation_start',
        'asset_account',
        'accumulated_account',
        'expense_account',
        'cost_center_id',
        'status',
        'notes',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => AssetCategory::class,
            'acquired_on' => 'immutable_date',
            'depreciation_start' => 'immutable_date',
            'disposed_on' => 'immutable_date',
            'acquisition_ore' => MoneyCast::class,
            'residual_ore' => MoneyCast::class,
            'opening_accumulated_ore' => MoneyCast::class,
            'disposal_price_ore' => MoneyCast::class,
            'useful_life_months' => 'integer',
            'asset_account' => 'integer',
            'accumulated_account' => 'integer',
            'expense_account' => 'integer',
        ];
    }

    public function plan(): DepreciationPlan
    {
        return new DepreciationPlan($this->acquisition_ore->ore, $this->residual_ore->ore, $this->useful_life_months, $this->depreciation_start);
    }

    /**
     * Written off so far: what was written off before the register, plus
     * every booked month. Reads a preloaded `depreciations_sum_amount_ore`
     * when there is one (withSum), so a list asks one query.
     */
    public function accumulatedOre(): int
    {
        $booked = $this->getAttribute('depreciations_sum_amount_ore');

        if ($booked === null && ! array_key_exists('depreciations_sum_amount_ore', $this->getAttributes())) {
            $booked = $this->depreciations()->sum('amount_ore');
        }

        return $this->opening_accumulated_ore->ore + (int) $booked;
    }

    public function bookValueOre(): int
    {
        return $this->acquisition_ore->ore - $this->accumulatedOre();
    }

    public function isFullyDepreciated(): bool
    {
        return $this->accumulatedOre() >= $this->plan()->depreciableOre();
    }

    /** @return HasMany<FixedAssetDepreciation, $this> */
    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class);
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
