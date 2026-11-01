<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskPriority;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something one person in a company should do, delegated by the firm or the
 * company (ADR-028). Not räkenskapsinformation.
 *
 * @property int $id
 * @property int $company_id
 * @property string $title
 * @property ?string $description
 * @property TaskType $type
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property ?CarbonImmutable $due_date
 * @property ?int $assignee_id
 * @property int $created_by
 * @property ?CarbonImmutable $completed_at
 * @property ?int $completed_by
 * @property ?string $subject_type
 * @property ?string $subject_id
 * @property ?int $recurrence_id
 * @property ?string $period
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Task extends Model
{
    use BelongsToCompany;

    public const int TITLE_MAX = 200;

    public const int DESCRIPTION_MAX = 5000;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'title',
        'description',
        'type',
        'status',
        'priority',
        'due_date',
        'assignee_id',
        'created_by',
        'subject_type',
        'subject_id',
        'recurrence_id',
        'period',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'TODO',
        'priority' => 'NORMAL',
        'type' => 'OTHER',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_date' => 'immutable_date',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<TaskComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<TaskChecklistItem, $this> */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    /** @return BelongsTo<TaskRecurrence, $this> */
    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(TaskRecurrence::class, 'recurrence_id');
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', TaskStatus::openValues());
    }

    public function isOverdue(?CarbonImmutable $today = null): bool
    {
        $today ??= CarbonImmutable::today();

        return $this->status->isOpen() && $this->due_date !== null && $this->due_date->lessThan($today);
    }
}
