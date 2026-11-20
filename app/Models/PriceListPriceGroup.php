<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A campaign list aimed at one customer price group (ADR-082 §2). A campaign
 * without any such row is for everyone.
 *
 * @property int $id
 * @property int $company_id
 * @property int $price_list_id
 * @property int $customer_price_group_id
 */
class PriceListPriceGroup extends Pivot
{
    use BelongsToCompany;

    protected $table = 'price_list_price_group';

    public $incrementing = true;

    /** @var list<string> */
    protected $fillable = ['company_id', 'price_list_id', 'customer_price_group_id'];

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<CustomerPriceGroup, $this> */
    public function priceGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerPriceGroup::class, 'customer_price_group_id');
    }
}
