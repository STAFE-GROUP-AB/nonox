<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A staffing target for an area or the whole company, a weekday (or every
 * day) and a time window, valid from a date: the sales one staffed hour
 * should carry, a minimum number of staff, or both (ADR-037, gap 1).
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $area_id
 * @property ?int $weekday
 * @property int $start_minute
 * @property int $end_minute
 * @property ?int $sales_per_staff_hour_ore
 * @property ?int $min_staff
 * @property CarbonImmutable $valid_from
 * @property ?int $created_by
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class StaffingRule extends Model
{
    use BelongsToCompany;

    protected $table = 'planning_staffing_rules';

    /** @var list<string> */
    protected $fillable = ['company_id', 'area_id', 'weekday', 'start_minute', 'end_minute', 'sales_per_staff_hour_ore', 'min_staff', 'valid_from', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'area_id' => 'integer',
            'weekday' => 'integer',
            'start_minute' => 'integer',
            'end_minute' => 'integer',
            'sales_per_staff_hour_ore' => 'integer',
            'min_staff' => 'integer',
            'valid_from' => 'immutable_date',
        ];
    }

    public static function clock(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
}
