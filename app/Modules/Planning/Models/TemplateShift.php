<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shift of a template: the week of the template (0-based), the weekday
 * (1 = Monday) and wall-clock times; an end at or before the start ends the
 * next day.
 *
 * @property int $id
 * @property int $company_id
 * @property int $template_id
 * @property int $week_index
 * @property int $weekday
 * @property string $start
 * @property string $end
 * @property int $break_minutes
 * @property ?int $employee_id
 * @property ?int $area_id
 * @property ?int $skill_id
 * @property ?string $note
 */
class TemplateShift extends Model
{
    use BelongsToCompany;

    protected $table = 'planning_template_shifts';

    /** @var list<string> */
    protected $fillable = ['company_id', 'template_id', 'week_index', 'weekday', 'start', 'end', 'break_minutes', 'employee_id', 'area_id', 'skill_id', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'template_id' => 'integer',
            'week_index' => 'integer',
            'weekday' => 'integer',
            'break_minutes' => 'integer',
            'employee_id' => 'integer',
            'area_id' => 'integer',
            'skill_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}
