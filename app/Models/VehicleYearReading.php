<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vehicle's odometer at the start and the end of a calendar year.
 * Skatteverket asks for both in a company car's körjournal (KJ-R9); a
 * company car's year cannot be closed without them.
 *
 * @property int $id
 * @property int $company_id
 * @property int $vehicle_id
 * @property int $year
 * @property ?int $start_odometer_km
 * @property ?int $end_odometer_km
 * @property ?CarbonImmutable $closed_at
 * @property ?int $closed_by
 */
class VehicleYearReading extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'vehicle_id',
        'year',
        'start_odometer_km',
        'end_odometer_km',
        'closed_at',
        'closed_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'start_odometer_km' => 'integer',
            'end_odometer_km' => 'integer',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
