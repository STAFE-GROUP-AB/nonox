<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Support\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A recurring task (uppgiftsmall, ADR-037 gap 4): the tasks that belong on
 * the shifts of an area and/or skill on certain weekdays, optionally at a
 * time of day that must fall inside the shift. Put on shifts as
 * ShiftTask rows when a shift is saved or a period is published. The text
 * must not hold personal data.
 *
 * @property int $id
 * @property int $company_id
 * @property string $title
 * @property ?string $instructions
 * @property ?int $area_id
 * @property ?int $skill_id
 * @property list<int> $weekdays
 * @property ?string $due_time
 * @property ?CarbonImmutable $valid_from
 * @property ?CarbonImmutable $valid_to
 * @property bool $is_active
 * @property ?int $created_by
 */
class TaskTemplate extends Model
{
    use BelongsToCompany;

    /** The fields a change can touch, for the audit log (names only, never the text). */
    public const array FIELDS = ['title', 'instructions', 'area_id', 'skill_id', 'weekdays', 'due_time', 'valid_from', 'valid_to', 'is_active'];

    protected $table = 'planning_task_templates';

    /** @var list<string> */
    protected $fillable = ['company_id', 'title', 'instructions', 'area_id', 'skill_id', 'weekdays', 'due_time', 'valid_from', 'valid_to', 'is_active', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'area_id' => 'integer',
            'skill_id' => 'integer',
            'weekdays' => 'array',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Whether the template puts its task on a shift that starts at $start and
     * ends at $end: active, inside its validity, the weekday of the day the
     * shift starts on, the area and skill when it names them, and the time of
     * day inside the shift when it names one.
     */
    public function matches(?int $areaId, ?int $skillId, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $start = $start->setTimezone('Europe/Stockholm');
        $day = $start->startOfDay();

        if (! $this->is_active
            || ($this->valid_from !== null && $day->toDateString() < $this->valid_from->toDateString())
            || ($this->valid_to !== null && $day->toDateString() > $this->valid_to->toDateString())
            || ! in_array($start->isoWeekday(), array_map('intval', $this->weekdays), true)
            || ($this->area_id !== null && $this->area_id !== $areaId)
            || ($this->skill_id !== null && $this->skill_id !== $skillId)) {
            return false;
        }

        if ($this->due_time === null) {
            return true;
        }

        // A shift past midnight holds the time on its first or its second day.
        foreach ([$day, $day->addDay()] as $date) {
            $at = CarbonImmutable::parse($date->toDateString().' '.$this->due_time, 'Europe/Stockholm');

            if ($at->greaterThanOrEqualTo($start) && $at->lessThan($end)) {
                return true;
            }
        }

        return false;
    }
}
