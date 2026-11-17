<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support\Demand;

use App\Modules\Planning\Models\Area;

/**
 * Where demand meets cost centres (ADR-037, gaps 1 and 7). An area's sales
 * can be read from the books only when the area has a cost centre and the
 * revenue rows carry it. The column planning_areas.cost_center_id arrives
 * with package P2; until then every area answers null and demand uses the
 * whole company's sales, saying so. Nothing else needs to change when the
 * column exists: BookedSales filters voucher_rows.cost_center_id on the id
 * returned here.
 */
final class AreaCostCentre
{
    public static function of(?Area $area): ?int
    {
        $id = $area?->getAttribute('cost_center_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
