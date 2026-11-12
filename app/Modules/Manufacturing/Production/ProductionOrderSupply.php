<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Production;

use App\Models\Company;
use App\Modules\Inventory\Purchasing\PurchaseSupplySource;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * What open production orders will make (ADR-071 §5, §8): the finished
 * goods of released and started orders count as coming in the purchase
 * proposal, so an article the company makes for a customer order is not
 * proposed for purchase as well. One query.
 */
final class ProductionOrderSupply implements PurchaseSupplySource
{
    public function coming(Company $company): array
    {
        return CurrentCompany::run($company, fn (): array => ProductionOrder::query()
            ->whereIn('status', [ProductionOrderStatus::Released->value, ProductionOrderStatus::Started->value])
            ->groupBy('article_id')
            ->select('article_id', DB::raw('sum(quantity_milli) as milli'))
            ->pluck('milli', 'article_id')
            ->map(fn (mixed $milli): int => (int) $milli)
            ->all());
    }
}
