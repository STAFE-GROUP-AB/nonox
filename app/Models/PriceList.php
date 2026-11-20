<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\PriceChannel;
use App\Domain\Pricing\PriceListKind;
use App\Domain\Pricing\PriceRounding;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of prices with its own currency, VAT basis and validity
 * (ADR-082 §2): a customer group's prices, a campaign, or a channel's prices.
 * A derived list follows the base price (or another list) by a percentage
 * and a rounding rule; its own items override the rule per article.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property PriceListKind $kind
 * @property string $currency
 * @property bool $prices_include_vat
 * @property ?CarbonImmutable $valid_from
 * @property ?CarbonImmutable $valid_to
 * @property bool $is_default_for_currency
 * @property ?int $based_on_list_id
 * @property ?int $derived_percent_bp
 * @property PriceRounding $rounding_rule
 * @property ?PriceChannel $channel
 * @property ?CarbonImmutable $archived_at
 * @property int $created_by
 */
class PriceList extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'code', 'name', 'kind', 'currency', 'prices_include_vat', 'valid_from', 'valid_to',
        'is_default_for_currency', 'based_on_list_id', 'derived_percent_bp', 'rounding_rule', 'channel',
        'archived_at', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'kind' => 'STANDARD',
        'currency' => 'SEK',
        'prices_include_vat' => false,
        'is_default_for_currency' => false,
        'rounding_rule' => 'NONE',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => PriceListKind::class,
            'prices_include_vat' => 'boolean',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'is_default_for_currency' => 'boolean',
            'derived_percent_bp' => 'integer',
            'rounding_rule' => PriceRounding::class,
            'channel' => PriceChannel::class,
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<PriceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    /** @return BelongsTo<PriceList, $this> */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'based_on_list_id');
    }

    /**
     * The customer price groups a campaign is for; none means everyone.
     *
     * @return BelongsToMany<CustomerPriceGroup, $this, PriceListPriceGroup>
     */
    public function priceGroups(): BelongsToMany
    {
        return $this->belongsToMany(CustomerPriceGroup::class, 'price_list_price_group')
            ->using(PriceListPriceGroup::class)
            ->withPivot('company_id')
            ->withTimestamps();
    }
}
