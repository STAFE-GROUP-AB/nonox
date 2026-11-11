<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Production;

use App\Models\Company;
use App\Modules\Inventory\Purchasing\PurchaseDemandSource;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\Models\ProductionOrderComponent;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Released and started production orders reserve what their components
 * still need (ADR-071 §5, §8): the order's need, loss included, less what
 * has already been issued, per stock article. One query.
 */
final class ProductionOrderDemand implements PurchaseDemandSource
{
    public function label(): string
    {
        return __('Tillverkningsorder');
    }

    public function reserved(Company $company): array
    {
        return CurrentCompany::run($company, fn (): array => ProductionOrderComponent::query()
            ->join('production_orders', 'production_orders.id', '=', 'production_order_components.production_order_id')
            ->join('articles', 'articles.id', '=', 'production_order_components.component_article_id')
            ->whereIn('production_orders.status', [ProductionOrderStatus::Released->value, ProductionOrderStatus::Started->value])
            ->where('articles.is_stock_item', true)
            ->whereColumn('production_order_components.issued_milli', '<', 'production_order_components.required_milli')
            ->groupBy('production_order_components.component_article_id')
            ->select('production_order_components.component_article_id', DB::raw('sum(production_order_components.required_milli - production_order_components.issued_milli) as milli'))
            ->pluck('milli', 'component_article_id')
            ->map(fn (mixed $milli): int => (int) $milli)
            ->filter(fn (int $milli): bool => $milli > 0)
            ->all());
    }
}
