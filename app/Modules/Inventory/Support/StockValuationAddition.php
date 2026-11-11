<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Something besides the articles on the shelves that the year-end stock
 * valuation (BookStockValuation) values and books on its own account pair.
 * Tillverkning registers the work in progress of unfinished production
 * orders (ADR-071 §8) through StockValuationAdditions in its service
 * provider; Lager itself knows nothing of production.
 */
interface StockValuationAddition
{
    /** A stable key, stored with the valuation, such as WORK_IN_PROGRESS. */
    public function key(): string;

    /** A short Swedish label for the closing page, such as "Produkter i arbete". */
    public function label(): string;

    /** @return array{0: int, 1: int} the stock account and its change account */
    public function accounts(Company $company): array;

    /** @return array<int, string> BAS names for the accounts, to add them when the chart lacks them */
    public function accountNames(): array;

    /**
     * The value on a day and what it is made of. Runs inside the company, in
     * a fixed number of queries.
     *
     * @return array{value_ore: int, lines: list<array{reference: string, name: string, value_ore: int}>}
     */
    public function value(Company $company, CarbonImmutable $date): array;
}
