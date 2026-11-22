<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Prices in a price list were added, changed, ended or restored, or the list
 * itself changed (ADR-082 P9). Dispatched after commit with ids and a date
 * only: the first day the change can be seen in prices (null when it applies
 * from the start). NONOX API turns it into the webhook price_list.changed.
 */
final readonly class PriceListChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $companyId,
        public int $priceListId,
        public ?string $changedFrom = null,
    ) {}
}
