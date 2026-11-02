<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\StockLocation;

/**
 * The location tree of the current company. Two queries at most, whatever
 * its depth.
 */
final class LocationTree
{
    /**
     * The location and every location under it.
     *
     * @return list<int>
     */
    public static function subtreeIds(StockLocation $location): array
    {
        $children = StockLocation::query()->get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = [$location->id];
        $frontier = [$location->id];

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $id) {
                foreach ($children->get($id, collect()) as $child) {
                    $next[] = (int) $child->id;
                }
            }

            array_push($ids, ...$next);
            $frontier = $next;
        }

        return $ids;
    }
}
