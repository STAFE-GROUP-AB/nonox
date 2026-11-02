<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planned shift (pass). Without an employee it is an open shift. A draft
 * is seen only by the planners; publishing shows it to the employee. A
 * published shift that is changed goes back to draft until the next
 * publication, and keeps what the employee was shown in published_snapshot;
 * removing a published shift cancels it. The plan is never written to the
 * staff ledger (PLANNING_MODULE.md §1).
 *
 * @property string $id
 * @property int $company_id
 * @property ?int $employee_id
 * @property ?int $area_id
 * @property ?int $skill_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int $break_minutes
 * @property ?string $note
 * @property string $status
 * @property ?CarbonImmutable $published_at
 * @property ?int $published_by
 * @property ?array<string, mixed> $published_snapshot
 * @property ?CarbonImmutable $cancelled_at
 * @property ?int $cancelled_by
 * @property string $source
 * @property ?int $template_id
 * @property ?int $cost_estimate_ore
 * @property ?array<string, mixed> $cost_details
 * @property ?CarbonImmutable $cost_calculated_at
 * @property ?int $created_by
 * @property ?int $updated_by
 */
class Shift extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const string DRAFT = 'DRAFT';

    public const string PUBLISHED = 'PUBLISHED';

    public const string CANCELLED = 'CANCELLED';

    public const array SOURCES = ['MANUAL', 'TEMPLATE', 'COPY', 'SWAP', 'CLAIM'];

    protected $table = 'planning_shifts';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'employee_id',
        'area_id',
        'skill_id',
        'starts_at',
        'ends_at',
        'break_minutes',
        'note',
        'status',
        'source',
        'template_id',
        'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'area_id' => 'integer',
            'skill_id' => 'integer',
            'starts_at' => LocalTimestamp::class,
            'ends_at' => LocalTimestamp::class,
            'break_minutes' => 'integer',
            'published_at' => LocalTimestamp::class,
            'published_by' => 'integer',
            'published_snapshot' => 'array',
            'cancelled_at' => LocalTimestamp::class,
            'template_id' => 'integer',
            'cost_estimate_ore' => 'integer',
            'cost_details' => 'array',
            'cost_calculated_at' => LocalTimestamp::class,
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function isOpen(): bool
    {
        return $this->employee_id === null;
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** A draft that was published before: the employee still sees the published version. */
    public function hasUnpublishedChanges(): bool
    {
        return $this->status === self::DRAFT && $this->published_at !== null;
    }

    /** Real minutes from start to end, whatever summer time does that night. */
    public function grossMinutes(): int
    {
        return intdiv($this->ends_at->getTimestamp() - $this->starts_at->getTimestamp(), 60);
    }

    /** Minutes of work: the shift less its break. */
    public function workedMinutes(): int
    {
        return max(0, $this->grossMinutes() - $this->break_minutes);
    }

    /** The day the shift belongs to: the day it starts on, in Sweden. */
    public function day(): CarbonImmutable
    {
        return $this->starts_at->startOfDay();
    }

    /**
     * What the employee is shown of the shift, kept when it is published.
     *
     * @return array{employee_id: ?int, area_id: ?int, starts_at: string, ends_at: string, break_minutes: int, note: ?string}
     */
    public function snapshot(): array
    {
        return [
            'employee_id' => $this->employee_id,
            'area_id' => $this->area_id,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'break_minutes' => $this->break_minutes,
            'note' => $this->note,
        ];
    }
}
