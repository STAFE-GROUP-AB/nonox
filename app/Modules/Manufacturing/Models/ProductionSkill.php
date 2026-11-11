<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A skill in the workshop (ADR-071 §13.2): an operation may need one, and
 * only the people who have it are planned on it.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 */
class ProductionSkill extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'name'];

    /** @return BelongsToMany<Employee, $this> */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'production_employee_skills', 'skill_id', 'employee_id')->withPivot('company_id')->withTimestamps();
    }
}
