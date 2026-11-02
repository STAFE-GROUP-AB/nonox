<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\CostCenter;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\PersonalNumber;
use App\Modules\Payroll\Domain\Units;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PensionPlan;
use App\Modules\Payroll\Models\SalaryChange;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
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

        // The form never receives the stored personal number or bank details (they stay out of the
        // browser); an empty field when editing means "keep what is saved".
        if ($employee !== null) {
            foreach (['personal_number', 'bank_clearing', 'bank_account'] as $field) {
                if (blank($input[$field] ?? null)) {
                    $input[$field] = (string) $employee->{$field};
                }
            }
        }

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
            'tax_adjustment_type' => ['nullable', Rule::in(['NONE', 'PERCENT', 'TABLE', 'AMOUNT'])],
            'tax_adjustment_percent' => ['nullable', 'required_if:tax_adjustment_type,PERCENT', 'regex:/^\d{1,3}([.,]\d{1,2})?$/'],
            'tax_adjustment_table' => ['nullable', 'required_if:tax_adjustment_type,TABLE', 'integer', 'between:1,99'],
            'tax_adjustment_column' => ['nullable', 'required_if:tax_adjustment_type,TABLE', 'integer', 'between:1,9'],
            'tax_adjustment_amount' => ['nullable', 'required_if:tax_adjustment_type,AMOUNT', 'string'],
            'tax_adjustment_valid_from' => ['nullable', 'required_unless:tax_adjustment_type,NONE,null', 'date_format:Y-m-d'],
            'tax_adjustment_valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:tax_adjustment_valid_from'],
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
        // Without the field (an import, an older caller) the stored decision is kept.
        $adjustment = array_key_exists('tax_adjustment_type', $input) ? self::taxAdjustment($validated) : [];

        return CurrentCompany::run($company, fn (): Employee => DB::transaction(function () use ($user, $company, $validated, $employee, $salary, $personal, $adjustment): Employee {
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
                'tax_residence' => $validated['tax_residence'],
                'tax_table' => $validated['tax_table'] ?? null,
                'tax_column' => $validated['tax_column'] ?? null,
                'user_id' => $linkedUserId,
                ...$adjustment,
            ]);
            $bankRequested = $this->setBankAccount($user, $employee, $isNew, $validated['bank_clearing'] ?? null, $validated['bank_account'] ?? null);
            $changed = array_keys(array_diff_key($employee->getDirty(), array_flip(self::SENSITIVE)));
            $sensitiveChanged = array_values(array_intersect(array_keys($employee->getDirty()), self::SENSITIVE));
            $employee->save();

            // An ended employment is still the one edited; a new employment is only made for a new employee.
            $employment = Employment::query()->where('employee_id', $employee->id)->whereNull('end_date')->latest('start_date')->first()
                ?? Employment::query()->where('employee_id', $employee->id)->latest('start_date')->first()
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

            // With a salary history, a salary edited on the employee card is a new salary from this month.
            if ($employment->wasChanged('monthly_salary_ore') && $employment->monthly_salary_ore !== null && SalaryChange::query()->where('employment_id', $employment->id)->exists()) {
                SalaryChange::query()->updateOrCreate(
                    ['employment_id' => $employment->id, 'valid_from' => CarbonImmutable::today()->startOfMonth()->toDateString()],
                    ['employee_id' => $employee->id, 'monthly_salary_ore' => $employment->monthly_salary_ore, 'reason' => __('Ändrad i personalkortet')],
                );
            }

            // Only which fields changed is recorded; personal numbers and bank details never reach the log.
            Audit::record($isNew ? 'payroll.employee_created' : 'payroll.employee_updated', $employee, [
                'employee_number' => $employee->employee_number,
                'changed' => $changed,
                'sensitive_changed' => $sensitiveChanged,
            ], $company, $user);

            if ($bankRequested !== null) {
                Audit::record('payroll.bank_change_requested', $employee, $bankRequested, $company, $user);
            }

            return $employee;
        }));
    }

    /**
     * A new employee's salary account is saved at once. A changed account
     * on an existing employee waits for a second person to confirm it
     * (ConfirmBankChange), so nobody alone can redirect a salary; the
     * payment file is refused meanwhile. Returns the masked change for the
     * audit log, or null.
     *
     * @return array{old: string, new: string}|null
     */
    private function setBankAccount(User $user, Employee $employee, bool $isNew, ?string $clearing, ?string $account): ?array
    {
        $clearing = blank($clearing) ? null : (string) $clearing;
        $account = blank($account) ? null : (string) $account;

        if ($isNew || $employee->bank_account === null) {
            $employee->fill(['bank_clearing' => $clearing, 'bank_account' => $account]);

            return null;
        }

        if ($clearing === $employee->bank_clearing && $account === $employee->bank_account) {
            return null;
        }

        $employee->forceFill([
            'pending_bank' => ['bank_clearing' => $clearing, 'bank_account' => $account],
            'pending_bank_by' => $user->id,
            'pending_bank_at' => CarbonImmutable::now(),
        ]);

        return ['old' => self::mask($employee->bank_account), 'new' => self::mask($account)];
    }

    /**
     * Skatteverket's jämkning decision as stored on the employee (SFL 11
     * kap. 27 §, AUD-LON-01, #69): a percentage in basis points, another
     * table and column, or an amount in öre per payment, with the days it
     * applies to. A decision is only stored with a first day.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function taxAdjustment(array $validated): array
    {
        $type = blank($validated['tax_adjustment_type'] ?? null) ? 'NONE' : (string) $validated['tax_adjustment_type'];

        if ($type === 'NONE') {
            return ['tax_adjustment_type' => 'NONE', 'tax_adjustment_value' => null, 'tax_adjustment_table' => null, 'tax_adjustment_column' => null, 'tax_adjustment_valid_from' => null, 'tax_adjustment_valid_to' => null];
        }

        $value = null;

        if ($type === 'PERCENT') {
            $value = BigDecimal::of(str_replace(',', '.', (string) $validated['tax_adjustment_percent']))->multipliedBy(100)->toInt();

            if ($value > Units::BASIS_POINTS) {
                throw ValidationException::withMessages(['tax_adjustment_percent' => __('Procentsatsen kan vara högst 100.')]);
            }
        }

        if ($type === 'AMOUNT') {
            try {
                $value = Money::parse((string) $validated['tax_adjustment_amount'])->ore;
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['tax_adjustment_amount' => __('Beloppet går inte att läsa.')]);
            }

            if ($value < 0) {
                throw ValidationException::withMessages(['tax_adjustment_amount' => __('Beloppet kan inte vara negativt.')]);
            }
        }

        return [
            'tax_adjustment_type' => $type,
            'tax_adjustment_value' => $value,
            'tax_adjustment_table' => $type === 'TABLE' ? (int) $validated['tax_adjustment_table'] : null,
            'tax_adjustment_column' => $type === 'TABLE' ? (int) $validated['tax_adjustment_column'] : null,
            'tax_adjustment_valid_from' => $validated['tax_adjustment_valid_from'],
            'tax_adjustment_valid_to' => blank($validated['tax_adjustment_valid_to'] ?? null) ? null : $validated['tax_adjustment_valid_to'],
        ];
    }

    /**
     * The stored jämkning decision as the employee form shows it.
     *
     * @return array<string, string>
     */
    public static function taxAdjustmentForm(Employee $employee): array
    {
        $type = $employee->tax_adjustment_type ?? 'NONE';

        return [
            'tax_adjustment_type' => $type,
            'tax_adjustment_percent' => $type === 'PERCENT' && $employee->tax_adjustment_value !== null ? rtrim(rtrim(number_format($employee->tax_adjustment_value / 100, 2, ',', ''), '0'), ',') : '',
            'tax_adjustment_amount' => $type === 'AMOUNT' && $employee->tax_adjustment_value !== null ? Money::ofOre($employee->tax_adjustment_value)->format(withCurrency: false) : '',
            'tax_adjustment_table' => (string) $employee->tax_adjustment_table,
            'tax_adjustment_column' => (string) $employee->tax_adjustment_column,
            'tax_adjustment_valid_from' => (string) $employee->tax_adjustment_valid_from?->toDateString(),
            'tax_adjustment_valid_to' => (string) $employee->tax_adjustment_valid_to?->toDateString(),
        ];
    }

    public static function mask(?string $account): string
    {
        return $account === null ? '–' : '****'.substr($account, -4);
    }
}
