<?php

declare(strict_types=1);

namespace App\Modules\Saas\Models;

use App\Models\Customer;
use App\Modules\Saas\Domain\ContractStatus;
use App\Modules\Saas\Domain\ContractVersion;
use App\Modules\Saas\Domain\ExclusionReason;
use App\Modules\Saas\Domain\RevenueLine;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Domain\VersionCause;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One known state of a contract from a date (ADR-080 §3.1). Append-only: the
 * model refuses changes and the database trigger refuses UPDATE, DELETE and
 * TRUNCATE. A later change is a new version.
 *
 * @property int $id
 * @property int $company_id
 * @property SourceType $source_type
 * @property string $source_id
 * @property ?int $customer_id
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable $recorded_at
 * @property ?int $recorded_by
 * @property VersionCause $cause
 * @property ContractStatus $status
 * @property ?CarbonImmutable $starts_on
 * @property ?CarbonImmutable $cancel_effective_on
 * @property ?ExclusionReason $excluded
 * @property list<array<string, mixed>> $lines
 * @property ?array<string, mixed> $source_ref
 * @property string $sha256
 */
class SaasContractVersion extends Model
{
    use BelongsToCompany;

    public const ?string UPDATED_AT = null;

    protected $table = 'saas_contract_versions';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'source_type',
        'source_id',
        'customer_id',
        'effective_from',
        'recorded_at',
        'recorded_by',
        'cause',
        'status',
        'starts_on',
        'cancel_effective_on',
        'excluded',
        'lines',
        'source_ref',
        'sha256',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'customer_id' => 'integer',
            'effective_from' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
            'recorded_by' => 'integer',
            'cause' => VersionCause::class,
            'status' => ContractStatus::class,
            'starts_on' => 'immutable_date',
            'cancel_effective_on' => 'immutable_date',
            'excluded' => ExclusionReason::class,
            'lines' => 'array',
            'source_ref' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Contract versions cannot be changed; record a new version.');
        });

        static::deleting(function (): never {
            throw new LogicException('Contract versions cannot be deleted.');
        });
    }

    /** The version as the pure calculation reads it. */
    public function toDomain(): ContractVersion
    {
        return new ContractVersion(
            $this->id,
            $this->source_type,
            $this->source_id,
            $this->customer_id,
            $this->effective_from->toDateString(),
            $this->status,
            array_map(fn (array $line): RevenueLine => RevenueLine::fromArray($line), $this->lines),
            $this->starts_on?->toDateString(),
            $this->cancel_effective_on?->toDateString(),
            $this->excluded,
            $this->cause,
        );
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
