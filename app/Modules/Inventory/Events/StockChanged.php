<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Stock changed: one or more movements were posted (StockLedger).
 * Dispatched after commit with ids only, for the stock.changed webhook
 * (ADR-032) and for later listeners such as POS stock sync (ADR-039).
 */
final readonly class StockChanged implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<int>  $movementIds
     * @param  list<int>  $articleIds
     */
    public function __construct(
        public int $companyId,
        public array $movementIds,
        public array $articleIds,
        public string $type,
    ) {}
}
