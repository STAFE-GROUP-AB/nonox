<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Mileage\VehicleKind;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A car in the mileage log (ADR-072): a person's own car (PRIVATE_CAR, with
 * the person as user_id) or one of the company's cars (COMPANY_CAR). A
 * company car's driver and fuel benefit come from Lön → Förmånsbilar for
 * the trip date; one without a benefit that day is a pool car. A car that is
 * an asset of a sole trader's business gives no 2,50 kr deduction
 * (IL 16 kap. 27 §, KJ-R15).
 *
 * @property int $id
 * @property int $company_id
 * @property VehicleKind $kind
 * @property string $label
 * @property ?string $registration_number encrypted
 * @property ?string $fuel CarBenefitCar::FUELS
 * @property ?int $user_id
 * @property bool $is_business_asset
 * @property ?CarbonImmutable $archived_at
 */
class Vehicle extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'kind',
        'label',
        'registration_number',
        'fuel',
        'user_id',
        'is_business_asset',
        'archived_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => VehicleKind::class,
            'registration_number' => 'encrypted',
            'is_business_asset' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<VehicleTrip, $this> */
    public function trips(): HasMany
    {
        return $this->hasMany(VehicleTrip::class);
    }

    /** @return HasMany<VehicleYearReading, $this> */
    public function yearReadings(): HasMany
    {
        return $this->hasMany(VehicleYearReading::class);
    }

    public function isCompanyCar(): bool
    {
        return $this->kind === VehicleKind::CompanyCar;
    }

    /** "Volvo V60 (ABC123)" */
    public function displayName(): string
    {
        $registration = trim((string) $this->registration_number);

        return $registration === '' ? $this->label : "{$this->label} ({$registration})";
    }

    /** The registration number without spaces or dashes, upper case, for comparing with a car benefit. */
    public static function normaliseRegistration(?string $registration): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $registration));
    }
}
