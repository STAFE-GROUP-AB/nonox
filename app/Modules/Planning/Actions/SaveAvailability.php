<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Models\Availability;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\SelfService;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The employee's own availability in Min sida (PLANNING_MODULE.md §6): can,
 * cannot or would rather work, every week on a weekday or on one date, the
 * whole day or between two times. Only while the company lets staff give
 * their availability. The planner sees it in the schedule; it never blocks
 * a plan. Audited.
 */
final class SaveAvailability
{
    public const int MAX_ROWS = 60;

    public function __construct(private readonly SelfService $self) {}

    /** @param  array<string, mixed>  $input */
    public function __invoke(User $user, Company $company, array $input, ?Availability $availability = null): Availability
    {
        $employee = $this->employee($user, $company);
        $this->ensureOwn($user, $availability);

        $validated = Validator::make($input, [
            'kind' => ['required', Rule::in(Availability::KINDS)],
            'repeat' => ['required', Rule::in(['weekly', 'date'])],
            'weekday' => ['exclude_unless:repeat,weekly', 'required', 'integer', 'between:1,7'],
            'date' => ['exclude_unless:repeat,date', 'required', 'date_format:Y-m-d'],
            'whole_day' => ['boolean'],
            'from' => ['exclude_if:whole_day,true', 'required', 'date_format:H:i'],
            'to' => ['exclude_if:whole_day,true', 'required', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], [
            'kind' => __('Typ'),
            'weekday' => __('Veckodag'),
            'date' => __('Datum'),
            'from' => __('Från'),
            'to' => __('Till'),
            'note' => __('Anteckning'),
        ])->validate();

        if (isset($validated['date']) && CarbonImmutable::parse((string) $validated['date'], LocalTimestamp::ZONE)->lessThan(CarbonImmutable::today(LocalTimestamp::ZONE))) {
            throw ValidationException::withMessages(['date' => __('Välj en dag från och med i dag.')]);
        }

        if (isset($validated['from'], $validated['to']) && $validated['to'] <= $validated['from']) {
            throw ValidationException::withMessages(['to' => __('Sluttiden måste vara efter starttiden.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $employee, $validated, $availability): Availability {
            return DB::transaction(function () use ($user, $company, $employee, $validated, $availability): Availability {
                if ($availability === null && Availability::query()->where('employee_id', $employee->id)->count() >= self::MAX_ROWS) {
                    throw ValidationException::withMessages(['kind' => __('Du har redan många rader. Ta bort några du inte behöver.')]);
                }

                $before = $availability === null ? null : self::row($availability);
                $availability ??= new Availability(['employee_id' => $employee->id, 'created_by' => $user->id]);

                $availability->fill([
                    'kind' => $validated['kind'],
                    'date' => $validated['date'] ?? null,
                    'weekday' => isset($validated['weekday']) ? (int) $validated['weekday'] : null,
                    'from' => $validated['from'] ?? null,
                    'to' => $validated['to'] ?? null,
                    'note' => blank($validated['note'] ?? null) ? null : $validated['note'],
                ])->save();

                $after = self::row($availability);

                Audit::record($before === null ? 'planning.availability_created' : 'planning.availability_updated', $availability, $before === null
                    ? ['employee_id' => $employee->id, 'after' => $after]
                    : ['employee_id' => $employee->id, ...Audit::changes($before, $after)], $company, $user);

                return $availability;
            });
        });
    }

    public function delete(User $user, Company $company, Availability $availability): void
    {
        $employee = $this->employee($user, $company);
        $this->ensureOwn($user, $availability);

        CurrentCompany::run($company, function () use ($user, $company, $employee, $availability): void {
            DB::transaction(function () use ($user, $company, $employee, $availability): void {
                Audit::record('planning.availability_deleted', $availability, [
                    'employee_id' => $employee->id,
                    'before' => self::row($availability),
                ], $company, $user);
                $availability->delete();
            });
        });
    }

    /** @return array<string, mixed> */
    private static function row(Availability $availability): array
    {
        return [
            'kind' => $availability->kind,
            'date' => $availability->date?->toDateString(),
            'weekday' => $availability->weekday,
            'from' => $availability->from,
            'to' => $availability->to,
            'note' => $availability->note,
        ];
    }

    private function employee(User $user, Company $company): Employee
    {
        $employee = $this->self->employee($user, $company);

        if (! PlanningSettings::bool($this->self->settings($company), 'allow_availability')) {
            throw ValidationException::withMessages(['kind' => __('Din arbetsgivare tar inte emot tillgänglighet här.')]);
        }

        return $employee;
    }

    /** Someone else's row answers as if it did not exist. */
    private function ensureOwn(User $user, ?Availability $availability): void
    {
        if ($availability !== null && Gate::forUser($user)->denies('own', $availability)) {
            throw (new ModelNotFoundException)->setModel(Availability::class, [$availability->id]);
        }
    }
}
