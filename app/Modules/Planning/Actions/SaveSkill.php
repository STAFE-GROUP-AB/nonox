<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\EmployeeSkill;
use App\Modules\Planning\Models\Skill;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Skills (kompetenser): create, rename, remove, and which employees have
 * them. Removing a skill takes it off employees and shifts. Audited.
 */
final class SaveSkill
{
    use GuardsPlanning;

    public function __invoke(User $user, Company $company, string $name, ?Skill $skill = null): Skill
    {
        $this->guard($user, $company);

        $validated = Validator::make(['name' => trim($name)], [
            'name' => ['required', 'string', 'max:100', Rule::unique('planning_skills', 'name')->where('company_id', $company->id)->ignore($skill?->id)],
        ], [], ['name' => __('Kompetens')])->validate();

        return DB::transaction(function () use ($user, $company, $validated, $skill): Skill {
            $before = $skill?->only(['name']) ?? [];
            $skill ??= new Skill;
            $skill->fill($validated)->save();

            Audit::record($before === [] ? 'planning.skill_created' : 'planning.skill_updated', $skill, Audit::changes($before, $skill->only(['name'])), $company, $user);

            return $skill;
        });
    }

    public function delete(User $user, Company $company, Skill $skill): void
    {
        $this->guard($user, $company);

        DB::transaction(function () use ($user, $company, $skill): void {
            Audit::record('planning.skill_deleted', $skill, ['name' => $skill->name], $company, $user);
            $skill->delete();
        });
    }

    /**
     * Sets the skills an employee has.
     *
     * @param  list<int>  $skillIds
     */
    public function assign(User $user, Company $company, Employee $employee, array $skillIds): void
    {
        $this->guard($user, $company);
        $valid = Skill::query()->whereKey($skillIds)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        DB::transaction(function () use ($user, $company, $employee, $valid): void {
            $before = EmployeeSkill::query()->where('employee_id', $employee->id)->pluck('skill_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

            EmployeeSkill::query()->where('employee_id', $employee->id)->whereNotIn('skill_id', $valid)->delete();

            foreach (array_diff($valid, $before) as $skillId) {
                EmployeeSkill::create(['employee_id' => $employee->id, 'skill_id' => $skillId]);
            }

            sort($valid);

            if ($before !== $valid) {
                Audit::record('planning.employee_skills_updated', $employee, ['before' => $before, 'after' => $valid], $company, $user);
            }
        });
    }
}
