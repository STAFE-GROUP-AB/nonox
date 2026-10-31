<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Models;

use App\Modules\StaffLedger\Domain\BusinessType;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place of business (lokal) with its own staff ledger and hash chain.
 * Several companies at the same address each keep their own location and
 * ledger; a kiosk serves one company.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $address_line1
 * @property string $postal_code
 * @property string $city
 * @property BusinessType $business_type
 * @property ?string $latitude
 * @property ?string $longitude
 * @property int $radius_meters
 * @property bool $kiosk_enabled
 * @property bool $qr_enabled
 * @property bool $mobile_enabled
 * @property ?string $chain_start_hash
 * @property ?string $ledger_head_hash
 * @property ?CarbonImmutable $archived_at
 */
class Location extends Model
{
    use BelongsToCompany;

    protected $table = 'staff_ledger_locations';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'address_line1',
        'postal_code',
        'city',
        'business_type',
        'latitude',
        'longitude',
        'radius_meters',
        'kiosk_enabled',
        'qr_enabled',
        'mobile_enabled',
        'archived_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'radius_meters' => 'integer',
            'kiosk_enabled' => 'boolean',
            'qr_enabled' => 'boolean',
            'mobile_enabled' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function address(): string
    {
        return "{$this->address_line1}, {$this->postal_code} {$this->city}";
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }
}
