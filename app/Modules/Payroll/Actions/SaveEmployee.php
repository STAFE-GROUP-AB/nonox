<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\CostCenter;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\PersonalNumber;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PensionPlan;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or updates an employee and the current employment. Personal
 * numbers and bank details are encrypted and never written to the audit
 * log (PAYROLL_MODULE.md B.15). The tax table and column are what the
 * employee's tax decision says; nothing is guessed.
 */
class SaveEmployee
{
    private const array SENSITIVE = ['personal_number', 'bank_clearing', 'bank_account', 'iban', 'bic', 'emergency_contact', 'notes'];

    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?Employee $employee = null): Employee
    {
        app(ModuleManager::class)->ensureEnabled(PayrollModule::KEY, $company);
        Gate::forUser($user)->authorize('manage', [Employee::class, $company]);

        $validated = Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'personal_number' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (PersonalNumber::tryFrom((string) $value) === null) {
                    $fail(__('Skriv personnumret med tolv siffror, ÅÅÅÅMMDD-NNNN, och kontrollera siffrorna.'));
                }
            }],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'bank_clearing' => ['nullable', 'regex:/^\d{4,5}$/'],
            'bank_account' => ['nullable', 'regex:/^\d{4,12}$/'],
            'tax_residence' => ['required', Rule::in(['SE', 'NON_RESIDENT_SINK', 'NON_RESIDENT_A_SINK'])],
            'tax_table' => ['nullable', 'integer', 'between:1,99'],
            'tax_column' => ['nullable', 'integer', 'between:1,9'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'employment_type' => ['required', Rule::in(['TILLSVIDARE', 'ALLMAN_VISSTID', 'VIKARIAT', 'SASONG', 'PROVANSTALLNING', 'TIMANSTALLD'])],
            'salary_form' => ['required', Rule::in(['MONTHLY', 'HOURLY'])],
            'salary' => ['required', 'string'],
            'employment_rate' => ['nullable', 'integer', 'between:1,100'],
            'weekly_hours' => ['nullable', 'regex:/^\d{1,2}([.,]\d{1,2})?$/'],
            'position_title' => ['nullable', 'string', 'max:255'],
            'cost_center' => ['nullable', 'string', 'max:20'],
            'vacation_rule' => ['nullable', Rule::in(['SAMMALONEREGELN', 'PROCENTREGELN'])],
            // Semesterlagen 4 § gives at least 25 days; an agreement may give more.
            'vacation_days_per_year' => ['nullable', 'integer', 'between:25,40'],
            'vacation_payout_with_salary' => ['nullable', 'boolean'],
            'pension_plan_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($company): void {
                if (! $company->hasUser(User::query()->find((int) $value) ?? new User)) {
                    $fail(__('Användaren är inte medlem i företaget.'));
                }
            }],
        ], [
            'bank_clearing.regex' => __('Clearingnumret har fyra eller fem siffror.'),
            'bank_account.regex' => __('Kontonumret ska bara innehålla siffror.'),
        ], [
            'first_name' => __('förnamn'),
            'last_name' => __('efternamn'),
            'start_date' => __('anställningens startdag'),
            'vacation_days_per_year' => __('semesterdagar per år'),
        ])->validate();

        if ($validated['tax_residence'] === 'SE' && (blank($validated['tax_table'] ?? null) || blank($validated['tax_column'] ?? null))) {
            throw ValidationException::withMessages(['tax_table' => __('Fyll i skattetabell och kolumn från den anställdes skattebesked.')]);
        }

        try {
            $salary = Money::parse((string) $validated['salary']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['salary' => __('Lönen går inte att läsa. Skriv till exempel 32 000 eller 165,50.')]);
        }

        $personal = PersonalNumber::tryFrom((string) $validated['personal_number']);

        return CurrentCompany::run($company, fn (): Employee => DB::transaction(function () use ($user, $company, $validated, $employee, $salary, $personal): Employee {
            $costCenterId = null;

            if (filled($validated['cost_center'] ?? null)) {
                $costCenterId = CostCenter::query()->where('code', $validated['cost_center'])->value('id')
                    ?? throw ValidationException::withMessages(['cost_center' => __('Kostnadsstället :code finns inte.', ['code' => $validated['cost_center']])]);
            }

            $linkedUserId = blank($validated['user_id'] ?? null) ? null : (int) $validated['user_id'];

            if ($linkedUserId !== null && Employee::query()->where('user_id', $linkedUserId)->when($employee !== null, fn ($query) => $query->whereKeyNot($employee?->id))->exists()) {
                throw ValidationException::withMessages(['user_id' => __('Inloggningen hör redan till en annan anställd.')]);
            }

            $isNew = $employee === null;
            $employee ??= new Employee(['employee_number' => NumberSeries::take(NumberSeries::EMPLOYEES)]);
            $employee->fill([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'personal_number' => $personal?->digits,
                'birth_date' => $personal?->birthDate,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'city' => $validated['city'] ?? null,
                'bank_clearing' => $validated['bank_clearing'] ?? null,
                'bank_account' => $validated['bank_account'] ?? null,
                'tax_residence' => $validated['tax_residence'],
                'tax_table' => $validated['tax_table'] ?? null,
                'tax_column' => $validated['tax_column'] ?? null,
                'user_id' => $linkedUserId,
            ]);
            $changed = array_keys(array_diff_key($employee->getDirty(), array_flip(self::SENSITIVE)));
            $sensitiveChanged = array_values(array_intersect(array_keys($employee->getDirty()), self::SENSITIVE));
            $employee->save();

            $employment = Employment::query()->where('employee_id', $employee->id)->whereNull('end_date')->latest('start_date')->first()
                ?? new Employment(['employee_id' => $employee->id]);
            $employment->fill([
                'start_date' => $validated['start_date'],
                'employment_type' => $validated['employment_type'],
                'salary_form' => $validated['salary_form'],
                'monthly_salary_ore' => $validated['salary_form'] === 'MONTHLY' ? $salary->ore : null,
                'hourly_rate_ore' => $validated['salary_form'] === 'HOURLY' ? $salary->ore : null,
                'employment_rate_bp' => ((int) ($validated['employment_rate'] ?? 100)) * 100,
                'weekly_hours' => blank($validated['weekly_hours'] ?? null) ? null : str_replace(',', '.', (string) $validated['weekly_hours']),
                'position_title' => $validated['position_title'] ?? null,
                'cost_center_id' => $costCenterId,
                'vacation_rule' => blank($validated['vacation_rule'] ?? null) ? null : $validated['vacation_rule'],
                'vacation_days_per_year' => blank($validated['vacation_days_per_year'] ?? null) ? null : (int) $validated['vacation_days_per_year'],
                'vacation_payout_with_salary' => (bool) ($validated['vacation_payout_with_salary'] ?? false),
                'pension_plan_id' => blank($validated['pension_plan_id'] ?? null) ? null : PensionPlan::query()->findOrFail((int) $validated['pension_plan_id'])->id,
            ])->save();

            // Only which fields changed is recorded; personal numbers and bank details never reach the log.
            Audit::record($isNew ? 'payroll.employee_created' : 'payroll.employee_updated', $employee, [
                'employee_number' => $employee->employee_number,
                'changed' => $changed,
                'sensitive_changed' => $sensitiveChanged,
            ], $company, $user);

            return $employee;
        }));
    }
}
