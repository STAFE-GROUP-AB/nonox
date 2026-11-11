<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

/**
 * The additions the year-end stock valuation includes besides the articles
 * (StockValuationAddition). A singleton; Tillverkning registers its work
 * in progress.
 */
final class StockValuationAdditions
{
    /** @var array<string, StockValuationAddition> */
    private array $additions = [];

    public function register(StockValuationAddition $addition): void
    {
        $this->additions[$addition->key()] = $addition;
    }

    /** @return list<StockValuationAddition> */
    public function all(): array
    {
        return array_values($this->additions);
    }
}
