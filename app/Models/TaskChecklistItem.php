<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a task's checklist.
 *
 * @property int $id
 * @property int $company_id
 * @property int $task_id
 * @property string $label
 * @property int $position
 * @property ?CarbonImmutable $done_at
 * @property ?int $done_by
 */
class TaskChecklistItem extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'task_id', 'label', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'done_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
