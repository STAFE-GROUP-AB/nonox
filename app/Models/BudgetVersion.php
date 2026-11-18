<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Budget\BudgetKind;
use App\Domain\Budget\BudgetStatus;
use App\Domain\Budget\LockedBudgetVersion;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A budget (or, from E5, a forecast) for one fiscal year (ADR-081 gap 2). It
 * is a plan next to the books: never posted, never part of the hash chain. A
 * locked version is read-only (the model here, a trigger in the database).
 *
 * @property int $id
 * @property int $company_id
 * @property int $fiscal_year_id
 * @property string $name
 * @property BudgetKind $kind
 * @property BudgetStatus $status
 * @property bool $is_active
 * @property CarbonImmutable|null $forecast_cutoff
 * @property int|null $source_version_id
 * @property array<string, mixed>|null $method
 * @property int $created_by
 * @property int|null $locked_by
 * @property CarbonImmutable|null $locked_at
 * @property-read FiscalYear $fiscalYear
 */
class BudgetVersion extends Model
{
    use BelongsToCompany;

    /** Fields a locked version may still change. */
    private const array CHANGEABLE_WHEN_LOCKED = ['is_active', 'updated_at'];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'name',
        'kind',
        'status',
        'is_active',
        'forecast_cutoff',
        'source_version_id',
        'method',
        'created_by',
        'locked_by',
        'locked_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'kind' => 'BUDGET',
        'status' => 'DRAFT',
        'is_active' => false,
    ];

    protected static function booted(): void
    {
        static::updating(function (BudgetVersion $version): void {
            if ($version->getOriginal('status') !== BudgetStatus::Locked) {
                return;
            }

            if (array_diff(array_keys($version->getDirty()), self::CHANGEABLE_WHEN_LOCKED) !== []) {
                throw LockedBudgetVersion::make();
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => BudgetKind::class,
            'status' => BudgetStatus::class,
            'is_active' => 'boolean',
            'forecast_cutoff' => 'immutable_date',
            'method' => 'array',
            'locked_at' => 'immutable_datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->status === BudgetStatus::Locked;
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /** @return HasMany<BudgetLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }
}
