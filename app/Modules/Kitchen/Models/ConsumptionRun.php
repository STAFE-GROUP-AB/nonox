<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One nightly drawing of a day's till sales from a location's stock through
 * the recipes (ADR-073 §5, kitchen:consume). The source of its RECIPE_SALE
 * movements.
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $business_date
 * @property int $location_id
 * @property int $sale_rows
 * @property int $movements
 */
class ConsumptionRun extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    protected $table = 'kitchen_consumption_runs';

    /** @var list<string> */
    protected $fillable = ['company_id', 'business_date', 'location_id', 'sale_rows', 'movements'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'sale_rows' => 'integer',
            'movements' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
