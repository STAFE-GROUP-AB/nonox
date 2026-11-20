<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\PriceUpdateLineStatus;
use App\Domain\Pricing\PriceUpdateRefusal;
use App\Domain\Pricing\PriceUpdateTarget;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One price in a mass price update (ADR-082 §8): which price (the base price
 * or a list item), its old and new amount, from which date, and what became
 * of it. Together the lines are the preview before the run is applied and
 * the diff that stays after the uploaded file is pruned (decision 10).
 *
 * @property int $id
 * @property int $company_id
 * @property int $price_update_run_id
 * @property int $position
 * @property PriceUpdateTarget $target
 * @property ?int $price_list_id
 * @property ?int $article_id
 * @property ?string $article_number
 * @property ?int $unit_id
 * @property int $min_quantity_milli
 * @property ?int $current_item_id
 * @property ?int $old_minor
 * @property ?int $old_discount_bp
 * @property ?int $unrounded_minor
 * @property ?int $new_minor
 * @property ?int $new_discount_bp
 * @property ?int $cost_minor
 * @property CarbonImmutable $valid_from
 * @property ?CarbonImmutable $valid_to
 * @property PriceUpdateLineStatus $status
 * @property ?PriceUpdateRefusal $reason
 * @property ?list<string> $flags
 * @property ?CarbonImmutable $applied_at
 * @property ?CarbonImmutable $reverted_at
 */
class PriceUpdateRunLine extends Model
{
    use BelongsToCompany;

    /** The new price differs from the old by more than this, in basis points (ADR-082 §8). */
    public const int BIG_CHANGE_BP = 2500;

    /** Rounding moved the price by more than this, in basis points (ADR-082 §8). */
    public const int ROUNDING_MOVED_BP = 200;

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'price_update_run_id', 'position', 'target', 'price_list_id', 'article_id', 'article_number', 'unit_id',
        'min_quantity_milli', 'current_item_id', 'old_minor', 'old_discount_bp', 'unrounded_minor', 'new_minor',
        'new_discount_bp', 'cost_minor', 'valid_from', 'valid_to', 'status', 'reason', 'flags',
        'applied_at', 'reverted_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['min_quantity_milli' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target' => PriceUpdateTarget::class,
            'min_quantity_milli' => 'integer',
            'old_minor' => 'integer',
            'old_discount_bp' => 'integer',
            'unrounded_minor' => 'integer',
            'new_minor' => 'integer',
            'new_discount_bp' => 'integer',
            'cost_minor' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'status' => PriceUpdateLineStatus::class,
            'reason' => PriceUpdateRefusal::class,
            'flags' => 'array',
            'applied_at' => 'immutable_datetime',
            'reverted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PriceUpdateRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PriceUpdateRun::class, 'price_update_run_id');
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** The change in basis points of the old price, or null when there is no old price to compare with. */
    public function changeBp(): ?int
    {
        if ($this->old_minor === null || $this->new_minor === null || $this->old_minor === 0) {
            return null;
        }

        return intdiv(($this->new_minor - $this->old_minor) * 10000, $this->old_minor);
    }
}
