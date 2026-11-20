<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One term of a price agreement (ADR-082 §4): for one article (or, once
 * article groups exist, a group) or all articles; from a quantity, in a
 * unit; a fixed price, a discount on the base price, or a discount on
 * another list ("the reseller list minus 3 %"). The database checks that
 * exactly one target and exactly one of price and discount is given.
 *
 * @property int $id
 * @property int $company_id
 * @property int $price_agreement_id
 * @property ?int $article_id
 * @property ?int $article_group_id
 * @property bool $applies_to_all
 * @property ?int $unit_id
 * @property int $min_quantity_milli
 * @property ?int $price_minor
 * @property ?int $discount_bp
 * @property ?int $base_price_list_id
 * @property ?int $max_quantity_milli
 */
class PriceAgreementItem extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'price_agreement_id', 'article_id', 'article_group_id', 'applies_to_all', 'unit_id',
        'min_quantity_milli', 'price_minor', 'discount_bp', 'base_price_list_id', 'max_quantity_milli',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['applies_to_all' => false, 'min_quantity_milli' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'applies_to_all' => 'boolean',
            'min_quantity_milli' => 'integer',
            'price_minor' => 'integer',
            'discount_bp' => 'integer',
            'max_quantity_milli' => 'integer',
        ];
    }

    /** @return BelongsTo<PriceAgreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(PriceAgreement::class, 'price_agreement_id');
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<PriceList, $this> */
    public function basePriceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'base_price_list_id');
    }
}
