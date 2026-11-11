<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Assets\AssetCategory;
use App\Domain\Assets\DepreciationBasis;
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
 * Components (ADR-059 A.2.4): a split asset has the status SPLIT and is never
 * depreciated again; its components are assets with parent_id set, each with
 * its own plan on the carrying-amount basis. Land is never depreciated.
 *
 * Additions (ADR-010, one investment from several purchases): a later
 * purchase added to the asset raises acquisition_ore, which is always the
 * total. An addition made before depreciation starts joins the plan from its
 * first month; one made later is written off over the remaining life from
 * the month after it (and after the last booked month), so no booked month
 * is ever changed or caught up (additionTerms).
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
 * @property ?int $accumulated_account
 * @property ?int $expense_account
 * @property ?int $parent_id
 * @property ?int $total_useful_life_months the total life of a component (useful_life_months is what remains from the split)
 * @property DepreciationBasis $depreciation_basis
 * @property ?int $cost_center_id
 * @property string $status
 * @property ?CarbonImmutable $disposed_on
 * @property ?Money $disposal_price_ore
 * @property ?string $disposal_voucher_id
 * @property ?string $notes
 * @property ?string $acquisition_voucher_id
 * @property ?int $acquisition_voucher_row_id
 * @property ?string $supplier_invoice_id
 * @property int $created_by
 */
class FixedAsset extends Model
{
    use BelongsToCompany;

    public const string ACTIVE = 'ACTIVE';

    public const string DISPOSED = 'DISPOSED';

    /** Split into components: kept for its history, never depreciated or disposed of itself. */
    public const string SPLIT = 'SPLIT';

    /** @var array<string, string> the database default, also before the row is read back */
    protected $attributes = ['depreciation_basis' => 'COST'];

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
        'acquisition_voucher_id',
        'acquisition_voucher_row_id',
        'supplier_invoice_id',
        'created_by',
        'parent_id',
        'depreciation_basis',
        'total_useful_life_months',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => AssetCategory::class,
            'depreciation_basis' => DepreciationBasis::class,
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
            'parent_id' => 'integer',
            'total_useful_life_months' => 'integer',
        ];
    }

    /**
     * The plan; land's has no life and writes off nothing (S1-R3.2). Reads
     * preloaded additions when there are (with('additions')), so a list asks
     * one query for all of them.
     */
    public function plan(): DepreciationPlan
    {
        $additions = [];

        foreach ($this->planAdditions() as $addition) {
            $terms = $this->additionTerms($addition->added_on, $addition->booked_through);

            // Joined from the first month: part of the plan's own acquisition value.
            if ($terms['from']->equalTo($this->depreciation_start->startOfMonth())) {
                continue;
            }

            $additions[] = ['amount' => $addition->amount_ore->ore, 'start' => $terms['from'], 'life' => $terms['life']];
        }

        return new DepreciationPlan(
            $this->acquisition_ore->ore,
            $this->residual_ore->ore,
            $this->category->depreciates() ? $this->useful_life_months : 0,
            $this->depreciation_start,
            $this->opening_accumulated_ore->ore,
            $this->depreciation_basis,
            $additions,
        );
    }

    /**
     * From which month, and over how many months, an amount added on a day
     * is written off. Before depreciation has started (nothing booked and
     * the addition in or before the first month) it joins the plan from the
     * first month over the whole life. Otherwise it starts the month after
     * the addition, never in or before a month already booked
     * ($bookedThrough), and runs over what is left of the life, so the asset
     * is still fully written off in the plan's last month. A life of zero or
     * less means the plan has ended and the amount cannot be added.
     *
     * TODO(legal-verify): whether a later addition (förbättringsutgift,
     * K2 10.x / K3 17.5) should be written off over the remaining life of the
     * asset (chosen here) or over a life of its own, under K2 and K3.
     *
     * @return array{from: CarbonImmutable, life: int}
     */
    public function additionTerms(CarbonImmutable $addedOn, ?string $bookedThrough): array
    {
        $first = $this->depreciation_start->startOfMonth();
        $month = $addedOn->startOfMonth();

        if ($bookedThrough === null && $month->lessThanOrEqualTo($first)) {
            return ['from' => $first, 'life' => $this->useful_life_months];
        }

        $from = $month->addMonthNoOverflow();

        if ($bookedThrough !== null) {
            $from = $from->max(CarbonImmutable::parse("{$bookedThrough}-01")->addMonthNoOverflow());
        }

        $from = $from->max($first);
        $passed = ($from->year - $first->year) * 12 + ($from->month - $first->month);

        return ['from' => $from, 'life' => $this->useful_life_months - $passed];
    }

    /** @return iterable<FixedAssetAddition> */
    private function planAdditions(): iterable
    {
        if ($this->relationLoaded('additions')) {
            return $this->additions;
        }

        // A new asset has none; otherwise one query, never a lazy load.
        return $this->exists ? $this->additions()->get() : [];
    }

    /** The useful life applied (K3 17.23): a component's total life, else the plan's life. */
    public function appliedLifeMonths(): int
    {
        return $this->total_useful_life_months ?? $this->useful_life_months;
    }

    public function depreciates(): bool
    {
        return $this->category->depreciates();
    }

    public function isComponent(): bool
    {
        return $this->parent_id !== null;
    }

    public function isSplit(): bool
    {
        return $this->status === self::SPLIT;
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
        $depreciable = $this->category->depreciates() && $this->useful_life_months > 0
            ? max(0, $this->acquisition_ore->ore - $this->residual_ore->ore)
            : 0;

        return $this->accumulatedOre() >= $depreciable;
    }

    /** @return BelongsTo<FixedAsset, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'parent_id');
    }

    /** @return HasMany<FixedAsset, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'parent_id');
    }

    /**
     * The later purchases added to the asset, oldest first.
     *
     * @return HasMany<FixedAssetAddition, $this>
     */
    public function additions(): HasMany
    {
        return $this->hasMany(FixedAssetAddition::class)->orderBy('added_on')->orderBy('id');
    }

    /** The acquisition value booked when the asset was created, before any addition. */
    public function originalAcquisitionOre(): int
    {
        return $this->acquisition_ore->ore - (int) collect($this->planAdditions())->sum(fn (FixedAssetAddition $addition): int => $addition->amount_ore->ore);
    }

    /** @return HasMany<FixedAssetDepreciation, $this> */
    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class);
    }

    /**
     * The voucher that booked the purchase, when the asset was created from it.
     *
     * @return BelongsTo<Voucher, $this>
     */
    public function acquisitionVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'acquisition_voucher_id');
    }

    /** @return BelongsTo<VoucherRow, $this> */
    public function acquisitionVoucherRow(): BelongsTo
    {
        return $this->belongsTo(VoucherRow::class, 'acquisition_voucher_row_id');
    }

    /** @return BelongsTo<SupplierInvoice, $this> */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
