<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing;

use App\Models\Company;

/**
 * Something other than a purchase order that will bring articles into
 * stock, so the purchase proposal counts it as coming instead of
 * proposing to buy them (ADR-071 §5). Tillverkning registers the finished
 * goods of its open production orders with
 * PurchaseDemandSources::registerSupply().
 */
interface PurchaseSupplySource
{
    /**
     * What is coming per article, in milli-units of the article's stock
     * unit. Runs inside the company, in a fixed number of queries.
     *
     * @return array<int, int> article id => coming milli
     */
    public function coming(Company $company): array;
}
