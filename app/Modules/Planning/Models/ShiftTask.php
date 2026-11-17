<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Modules\Planning\Support\LocalTimestamp;
use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A task on one shift (ADR-037 gap 4): copied from a TaskTemplate or written
 * for the shift, ticked off with the time and the user who ticked it. A work
 * aid, not a statutory record. A tick is personal data about work: it is
 * pruned (planning:prune-tasks), shown without a name and never summed up
 * per employee.
 *
 * @property int $id
 * @property int $company_id
 * @property string $shift_id
 * @property ?int $template_id
 * @property string $title
 * @property ?string $instructions
 * @property ?string $due_time
 * @property int $position
 * @property ?CarbonImmutable $done_at
 * @property ?int $done_by
 */
class ShiftTask extends Model
{
    use BelongsToCompany;

    protected $table = 'planning_shift_tasks';

    /** done_at and done_by are set only by TickShiftTask. @var list<string> */
    protected $fillable = ['company_id', 'shift_id', 'template_id', 'title', 'instructions', 'due_time', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'template_id' => 'integer',
            'position' => 'integer',
            'done_at' => LocalTimestamp::class,
            'done_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }
}
