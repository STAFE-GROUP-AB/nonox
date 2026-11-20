<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One price of an article in a price list (ADR-082 §2): a price in the list's
 * currency or a discount on the base price (exactly one, checked by the
 * database), from a quantity, in a unit, for a period. Several items for one
 * article form a quantity ladder; a price change is a new item with a later
 * valid_from, so the old price stays answerable.
 *
 * @property int $id
 * @property int $company_id
 * @property int $price_list_id
 * @property int $article_id
 * @property ?int $unit_id
 * @property int $min_quantity_milli
 * @property ?int $price_minor
 * @property ?int $discount_bp
 * @property ?CarbonImmutable $valid_from
 * @property ?CarbonImmutable $valid_to
 * @property ?int $price_update_run_id
 * @property ?CarbonImmutable $archived_at
 */
class PriceListItem extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'price_list_id', 'article_id', 'unit_id', 'min_quantity_milli', 'price_minor', 'discount_bp',
        'valid_from', 'valid_to', 'price_update_run_id', 'archived_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['min_quantity_milli' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'min_quantity_milli' => 'integer',
            'price_minor' => 'integer',
            'discount_bp' => 'integer',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
