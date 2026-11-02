<?php

declare(strict_types=1);

namespace App\Modules\Planning\Models;

use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * That an employee has a skill.
 *
 * @property int $id
 * @property int $company_id
 * @property int $employee_id
 * @property int $skill_id
 */
class EmployeeSkill extends Model
{
    use BelongsToCompany;

    protected $table = 'planning_employee_skills';

    /** @var list<string> */
    protected $fillable = ['company_id', 'employee_id', 'skill_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['employee_id' => 'integer', 'skill_id' => 'integer'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
