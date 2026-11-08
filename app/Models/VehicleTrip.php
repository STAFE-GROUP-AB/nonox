<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Mileage\Distance;
use App\Domain\Mileage\TripKind;
use App\Domain\Mileage\TripStatus;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Expense;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One trip in the mileage log (ADR-072), typed by the person who drove it.
 * Places and purpose are encrypted; a private trip keeps only date and
 * distance (KJ-R19). A trip under a paid claim or a posted voucher is
 * frozen here and by a PostgreSQL trigger; a mistake is corrected by a new
 * trip with a negative distance that points at it.
 *
 * @property string $id
 * @property int $company_id
 * @property int $vehicle_id
 * @property ?int $driver_user_id
 * @property ?int $employee_id
 * @property CarbonImmutable $trip_date
 * @property TripKind $kind
 * @property int $distance_hm
 * @property ?int $odometer_start_km
 * @property ?int $odometer_end_km
 * @property ?string $from_place
 * @property ?string $to_place
 * @property ?string $purpose
 * @property ?int $project_id
 * @property ?bool $all_fuel_paid_by_driver
 * @property bool $charged_at_workplace
 * @property TripStatus $status
 * @property ?int $expense_id
 * @property ?string $voucher_id
 * @property ?string $corrects_trip_id
 * @property ?string $correction_reason
 * @property ?int $created_by
 * @property-read Vehicle $vehicle
 * @property-read ?Project $project
 */
class VehicleTrip extends Model
{
    use BelongsToCompany;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'vehicle_id',
        'driver_user_id',
        'employee_id',
        'trip_date',
        'kind',
        'distance_hm',
        'odometer_start_km',
        'odometer_end_km',
        'from_place',
        'to_place',
        'purpose',
        'project_id',
        'all_fuel_paid_by_driver',
        'charged_at_workplace',
        'status',
        'expense_id',
        'voucher_id',
        'corrects_trip_id',
        'correction_reason',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'DRAFT',
        'charged_at_workplace' => false,
    ];

    protected static function booted(): void
    {
        $guard = function (VehicleTrip $trip): void {
            $original = TripStatus::tryFrom((string) $trip->getRawOriginal('status'));

            if ($original?->isSettled() === true) {
                throw new RuntimeException('A paid or booked trip is underlag to a verifikation and cannot be changed.');
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trip_date' => 'immutable_date',
            'kind' => TripKind::class,
            'status' => TripStatus::class,
            'distance_hm' => 'integer',
            'odometer_start_km' => 'integer',
            'odometer_end_km' => 'integer',
            'from_place' => 'encrypted',
            'to_place' => 'encrypted',
            'purpose' => 'encrypted',
            'correction_reason' => 'encrypted',
            'all_fuel_paid_by_driver' => 'boolean',
            'charged_at_workplace' => 'boolean',
        ];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function distanceLabel(): string
    {
        return Distance::formatKm($this->distance_hm);
    }

    /** "Kontoret – Kund i Lund", or empty for a private trip. */
    public function route(): string
    {
        return implode(' – ', array_filter([trim((string) $this->from_place), trim((string) $this->to_place)]));
    }
}
