<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The company's own estimate of how a weekday's sales spread over the hours
 * ("Egen uppskattning"), for an area or the whole company: 24 shares in
 * basis points, hour 00 first, summing to 10 000. Used only when no till
 * gives the shape.
 *
 * @property int $id
 * @property int $company_id
 * @property ?int $area_id
 * @property int $weekday
 * @property list<int> $shares
 * @property ?int $updated_by
 * @property ?CarbonInterface $created_at
 * @property ?CarbonInterface $updated_at
 */
class HourProfile extends Model
{
    use BelongsToCompany;

    public const int WHOLE = 10_000;

    protected $table = 'planning_hour_profiles';

    /** @var list<string> */
    protected $fillable = ['company_id', 'area_id', 'weekday', 'shares', 'updated_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'area_id' => 'integer',
            'weekday' => 'integer',
            'shares' => 'array',
        ];
    }
}
