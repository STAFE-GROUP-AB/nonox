<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing;

use App\Models\Company;

/**
 * Something that will take articles out of stock and so reserves them in
 * the purchase proposal (ADR-071 §5: "free now = on hand − reserved").
 * Lager ships open customer orders (OpenCustomerOrderDemand). The
 * manufacturing build adds the components of open production orders,
 * exploded through their bills of materials, by registering its own source
 * with PurchaseDemandSources::register() in its service provider.
 */
interface PurchaseDemandSource
{
    /** A short Swedish label for the proposal page, such as "Öppna kundordrar". */
    public function label(): string;

    /**
     * What is reserved per article, in milli-units of the article's stock
     * unit. Runs inside the company, in a fixed number of queries.
     *
     * @return array<int, int> article id => reserved milli
     */
    public function reserved(Company $company): array;
}
