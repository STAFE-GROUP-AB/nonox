<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Models;

use App\Models\User;
use App\Modules\Payroll\Domain\PersonalNumber;
use App\Modules\StaffLedger\Domain\CheckInMethod;
use App\Modules\StaffLedger\Domain\CorrectionKind;
use App\Modules\StaffLedger\Domain\Direction;
use App\Modules\StaffLedger\Domain\EntryType;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One entry in the staff ledger. Append-only: the model refuses changes and
 * deletes, a PostgreSQL trigger backs it up, and every entry carries
 * hash = sha256(previous_hash || canonical_json(entry)) in a chain per
 * company and location (EntryHash). The person's name, personal number and
 * employer are copied into the entry, so the ledger shows what was true when
 * it was written even if the register changes later.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $location_id
 * @property ?int $person_id
 * @property EntryType $type
 * @property ?Direction $direction
 * @property ?CorrectionKind $correction
 * @property ?int $corrects_entry_id
 * @property CheckInMethod $method
 * @property string $person_name
 * @property string $personal_number
 * @property ?string $employer_name
 * @property ?string $employer_org_number
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $received_at
 * @property ?int $device_id
 * @property ?int $device_sequence
 * @property ?string $client_hash
 * @property ?int $clock_skew_seconds
 * @property ?int $geo_distance_meters
 * @property list<string> $flags
 * @property ?int $user_id
 * @property ?string $reason
 * @property ?string $previous_hash
 * @property string $hash
 * @property CarbonImmutable $created_at
 */
class Entry extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    protected $table = 'staff_ledger_entries';

    /** @var list<string> */
    protected $hidden = ['personal_number'];

    /** @var list<string> */
    protected $fillable = [
        'uuid',
        'company_id',
        'location_id',
        'person_id',
        'type',
        'direction',
        'correction',
        'corrects_entry_id',
        'method',
        'person_name',
        'personal_number',
        'employer_name',
        'employer_org_number',
        'occurred_at',
        'received_at',
        'device_id',
        'device_sequence',
        'client_hash',
        'clock_skew_seconds',
        'geo_distance_meters',
        'flags',
        'user_id',
        'reason',
        'previous_hash',
        'hash',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => EntryType::class,
            'direction' => Direction::class,
            'correction' => CorrectionKind::class,
            'method' => CheckInMethod::class,
            'personal_number' => 'encrypted',
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'device_sequence' => 'integer',
            'clock_skew_seconds' => 'integer',
            'geo_distance_meters' => 'integer',
            'flags' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Staff ledger entries cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Staff ledger entries cannot be deleted.');
        });
    }

    public function maskedPersonalNumber(): string
    {
        return PersonalNumber::masked($this->personal_number);
    }

    public function formattedPersonalNumber(): string
    {
        return PersonalNumber::tryFrom($this->personal_number)?->formatted() ?? $this->personal_number;
    }

    /** IN or OUT for check-ins, check-outs and manual entries; null for corrections. */
    public function effectiveDirection(): ?Direction
    {
        return match ($this->type) {
            EntryType::CheckIn => Direction::In,
            EntryType::CheckOut => Direction::Out,
            EntryType::Manual => $this->direction,
            EntryType::Correction => null,
        };
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
