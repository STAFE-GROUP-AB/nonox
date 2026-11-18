<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Domain\LocationType;
use App\Modules\Inventory\Models\ArticleLocationSetting;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;

/**
 * Stock per warehouse for the per-warehouse reorder points (ADR-081 E13): what
 * is on the shelves anywhere under the warehouse, plus what is on its way
 * to it in the transit locations. Goods on their way out no longer count for
 * the sender. Three queries, whatever the number of articles; runs inside the
 * company.
 */
final class WarehouseStock
{
    /**
     * @param  list<int>  $articleIds
     * @return array<int, array<int, int>> article id => warehouse id => thousandths
     */
    public static function forArticles(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }

        $roots = LocationTree::warehouseIds();
        $destination = StockLocation::query()->where('type', LocationType::Transit->value)->pluck('transit_to_id', 'id');
        $result = [];

        foreach (StockLevel::query()->whereIn('article_id', $articleIds)->where('quantity_milli', '<>', 0)->get(['article_id', 'location_id', 'quantity_milli']) as $level) {
            $warehouse = $destination->has($level->location_id) ? (int) $destination[$level->location_id] : ($roots[$level->location_id] ?? null);

            if ($warehouse === null) {
                continue;
            }

            $result[$level->article_id][$warehouse] = ($result[$level->article_id][$warehouse] ?? 0) + $level->quantity_milli;
        }

        return $result;
    }

    /**
     * How much the warehouses of an article lack, by the article's
     * per-warehouse settings: for each warehouse at or below its point, the
     * larger of its reorder quantity and what brings it back to the point.
     *
     * @param  iterable<ArticleLocationSetting>  $settings  the settings of one article
     * @param  array<int, int>  $stockByWarehouse
     * @return array{below: bool, refill: int}
     */
    public static function shortfall(iterable $settings, array $stockByWarehouse): array
    {
        $below = false;
        $refill = 0;

        foreach ($settings as $setting) {
            $have = $stockByWarehouse[$setting->warehouse_id] ?? 0;

            if ($have > $setting->reorder_point_milli) {
                continue;
            }

            $below = true;
            $refill += max($setting->reorder_quantity_milli ?? 0, $setting->reorder_point_milli - $have);
        }

        return ['below' => $below, 'refill' => $refill];
    }
}
