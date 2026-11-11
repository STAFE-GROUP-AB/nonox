<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\Manufacturing\Domain\DeviationStatus;
use App\Modules\Manufacturing\Domain\DeviationType;
use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Something that moved the plan (ADR-071 §13.5) and what it did to the
 * deliveries: the run before and the run after, with an impact per affected
 * production order. An ABSENCE keeps only the employee id and the dates,
 * never the absence or its reason (§13.7). After 24 months the employee id
 * is removed and the rest kept for delivery accuracy.
 *
 * @property int $id
 * @property int $company_id
 * @property DeviationType $type
 * @property DeviationStatus $status
 * @property ?string $source_type
 * @property ?int $source_id
 * @property ?int $employee_id
 * @property ?CarbonImmutable $starts_on
 * @property ?CarbonImmutable $ends_on
 * @property array<string, mixed> $details
 * @property ?int $run_before_id
 * @property ?int $run_after_id
 * @property ?string $resolution
 * @property ?int $handled_by
 * @property ?CarbonImmutable $handled_at
 * @property ?int $created_by
 * @property ?CarbonImmutable $created_at
 */
class ProductionDeviation extends Model
{
    use BelongsToCompany;

    public const string NO_IMPACT = 'NO_IMPACT';

    public const int RETENTION_MONTHS = 24;

    /** @var list<string> */
    protected $fillable = ['company_id', 'type', 'status', 'source_type', 'source_id', 'employee_id', 'starts_on', 'ends_on', 'details', 'run_before_id', 'run_after_id', 'resolution', 'handled_by', 'handled_at', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'OPEN', 'details' => '{}'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DeviationType::class,
            'status' => DeviationStatus::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'details' => 'array',
            'handled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', DeviationStatus::Open->value);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<ProductionDeviationImpact, $this> */
    public function impacts(): HasMany
    {
        return $this->hasMany(ProductionDeviationImpact::class, 'deviation_id')->orderByDesc('change_working_days')->orderBy('id');
    }
}
