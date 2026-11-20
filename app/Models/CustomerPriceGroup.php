<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of customers with one price list, "Återförsäljare" or "Offentlig
 * sektor" (ADR-082 §3): a campaign can aim at it, and many customers move to
 * a new list in one change. A customer's own list wins over its group's.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property ?int $price_list_id
 * @property ?CarbonImmutable $archived_at
 */
class CustomerPriceGroup extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'code', 'name', 'price_list_id', 'archived_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'price_group_id');
    }
}
