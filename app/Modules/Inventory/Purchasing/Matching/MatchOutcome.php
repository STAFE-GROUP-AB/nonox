<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Matching;

use App\Modules\Inventory\Models\PurchaseMatchResult;
use Illuminate\Support\Collection;

/** What a new comparison did: the current results, and whether they replaced an earlier set. */
final readonly class MatchOutcome
{
    /** @param  Collection<int, PurchaseMatchResult>  $results */
    public function __construct(
        public Collection $results,
        public bool $changed,
        public bool $hadPrevious,
    ) {}

    /** @return Collection<int, PurchaseMatchResult> */
    public function open(): Collection
    {
        return $this->results->filter(fn (PurchaseMatchResult $result): bool => $result->isOpen())->values();
    }
}
