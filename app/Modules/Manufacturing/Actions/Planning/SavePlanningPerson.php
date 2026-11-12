<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Planning;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Models\ProductionSkill;
use App\Modules\Manufacturing\Models\WorkPattern;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\PlanTriggers;
use App\Modules\Payroll\Models\Employee;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A person in the workshop (ADR-071 §13.2): the skills they have and,
 * when Planering is off, their working week. A person with no week of
 * their own works the company's hours; a person with neither skills nor a
 * week is not planned.
 */
class SavePlanningPerson
{
    use GuardsManufacturing;

    /**
     * @param  array<string, mixed>  $input  skill_ids, own_week (bool), days: weekday → {start, end, break_start, break_minutes} (an empty start is a day off)
     */
    public function __invoke(User $user, Company $company, Employee $employee, array $input): void
    {
        $this->guard($user, $company, 'manage');

        $days = [];

        if ((bool) ($input['own_week'] ?? false)) {
            foreach ((array) ($input['days'] ?? []) as $weekday => $day) {
                $weekday = (int) $weekday;
                $start = trim((string) ($day['start'] ?? ''));

                if ($weekday < 1 || $weekday > 7 || $start === '') {
                    continue;
                }

                $from = Clock::minuteOfDay($start);
                $to = Clock::minuteOfDay(trim((string) ($day['end'] ?? '')));
                $breakText = trim((string) ($day['break_start'] ?? ''));
                $break = $breakText === '' ? null : Clock::minuteOfDay($breakText);
                $breakMinutes = (int) ($day['break_minutes'] ?? 0);

                if ($from === null || $to === null || $to <= $from || ($breakText !== '' && $break === null) || $breakMinutes < 0 || $breakMinutes > 240) {
                    throw ValidationException::withMessages(["days.{$weekday}" => __('Skriv tiderna som 07:00 och 16:00, med sluttiden efter starttiden och en rast på högst fyra timmar.')]);
                }

                $days[$weekday] = ['start_time' => Clock::timeOfDay($from), 'end_time' => Clock::timeOfDay($to), 'break_start' => $break === null ? null : Clock::timeOfDay($break), 'break_minutes' => $break === null ? 0 : $breakMinutes];
            }

            if ($days === []) {
                throw ValidationException::withMessages(['days' => __('Ange minst en arbetsdag, eller låt personen följa företagets arbetstider.')]);
            }
        }

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $employee, $input, $days): void {
            $employee = Employee::query()->findOrFail($employee->id);
            $skillIds = ProductionSkill::query()->whereIn('id', array_map('intval', (array) ($input['skill_ids'] ?? [])))->pluck('id')->all();

            DB::table('production_employee_skills')->where('company_id', $company->id)->where('employee_id', $employee->id)->whereNotIn('skill_id', $skillIds)->delete();

            foreach ($skillIds as $skillId) {
                DB::table('production_employee_skills')->insertOrIgnore(['company_id' => $company->id, 'employee_id' => $employee->id, 'skill_id' => $skillId, 'created_at' => now(), 'updated_at' => now()]);
            }

            WorkPattern::query()->where('employee_id', $employee->id)->delete();

            foreach ($days as $weekday => $day) {
                WorkPattern::create(['employee_id' => $employee->id, 'weekday' => $weekday, ...$day]);
            }

            Audit::record('manufacturing.work_pattern_saved', $employee, ['skill_ids' => $skillIds, 'own_week_days' => array_keys($days)], $company, $user);
            PlanTriggers::recompute($company->id, PlanTrigger::Resources);
        }));
    }
}
