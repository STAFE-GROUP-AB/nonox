<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A till connection (ADR-042) whose receipts give the shape of the day for
 * an area, or for the whole company when area_id is null. Only the shape:
 * the daily total stays the books' (ADR-037, gap 1). No row: not used.
 *
 * @property int $id
 * @property int $company_id
 * @property int $pos_connection_id
 * @property ?int $area_id
 * @property ?int $created_by
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class SalesSource extends Model
{
    use BelongsToCompany;

    protected $table = 'planning_sales_sources';

    /** @var list<string> */
    protected $fillable = ['company_id', 'pos_connection_id', 'area_id', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pos_connection_id' => 'integer',
            'area_id' => 'integer',
        ];
    }
}
