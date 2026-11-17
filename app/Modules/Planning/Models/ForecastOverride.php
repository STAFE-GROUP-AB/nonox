<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A person's own figure for one day's sales, replacing the forecast for an
 * area or the whole company, with the reason (an event nearby). Shown as an
 * override wherever the forecast is shown. The reason never goes into the
 * audit log.
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $area_id
 * @property CarbonImmutable $date
 * @property int $amount_ore
 * @property string $reason
 * @property ?int $created_by
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class ForecastOverride extends Model
{
    use BelongsToCompany;

    protected $table = 'planning_forecast_overrides';

    /** @var list<string> */
    protected $fillable = ['company_id', 'area_id', 'date', 'amount_ore', 'reason', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'area_id' => 'integer',
            'date' => 'immutable_date',
            'amount_ore' => 'integer',
        ];
    }
}
