<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\VacationYearClosing;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\RulePresenter;
use App\Modules\Payroll\Support\Vacation\VacationLedger;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a semesterår for every employee (semesterlagen 18, 26, 26 a §§):
 *
 * - Paid days of the year not taken may be saved, but only days beyond the
 *   20 that must be taken, and not in a year when saved days were used.
 * - Unused days that are not saved are paid with vacation pay (26 a §).
 * - Under sammalöneregeln, 12 % of the variable pay due during the year is
 *   paid as vacation pay (16 a, 26 §§).
 *
 * The amounts are paid in the next payroll run, after which the closing is
 * tied to that run and can never be paid twice.
 *
 * TODO(legal-verify): the limitation in 26 a § to days that together with
 * days earned exceed twenty-five; which pay counts as variable pay.
 */
class CloseVacationYear
{
    public function __construct(private readonly ModuleManager $modules, private readonly VacationLedger $ledger) {}

    /**
     * What closing the year would do, per employee.
     *
     * @return array<int, array{employee: Employee, earned: int, taken: int, saved_used: int, left: int, max_save: int, day_value_ore: int, variable_basis_ore: int, variable_pay_ore: int}>
     */
    public function preview(Company $company, CarbonImmutable $yearStart): array
    {
        $settings = $this->modules->settings(PayrollModule::KEY, $company);
        $yearEnd = $yearStart->addYear()->subDay();

        try {
            $rules = RuleSet::for($yearEnd);
            $mustTake = (int) ((array) $rules->get('vacation.max_saved_days'))['days_that_must_be_taken'];
            $percentBp = (int) ((array) $rules->get('vacation.percent_rule'))['bp'];
            $supplement = (array) $rules->get('vacation.supplement_sammaloneregeln');
        } catch (MissingPayrollRuleException $exception) {
            throw ValidationException::withMessages(['year' => __('Regeln :rule är inte kontrollerad för :date. Kontrollera den under Lön → Regler först.', ['rule' => RulePresenter::name($exception->key), 'date' => $yearEnd->toDateString()])]);
        }

        return CurrentCompany::run($company, function () use ($settings, $rules, $yearStart, $yearEnd, $mustTake, $percentBp, $supplement): array {
            $employments = Employment::query()
                ->whereDate('start_date', '<=', $yearEnd->toDateString())
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $yearStart->toDateString()))
                ->where('vacation_payout_with_salary', false)
                ->orderBy('start_date')
                ->get()
                ->keyBy('employee_id');
            $employees = Employee::query()->whereIn('id', $employments->keys())->orderBy('employee_number')->get();
            $balances = $this->ledger->overview($employees, $employments, $yearStart, $settings, $rules, $yearEnd);
            $variable = $this->variableBasis($employees, $yearStart, $yearEnd);
            $result = [];

            foreach ($employees as $employee) {
                /** @var Employment $employment */
                $employment = $employments->get($employee->id);
                $balance = $balances[$employee->id];
                $savedUsed = min($balance['saved'], max(0, $balance['taken'] - $balance['earned']));
                $left = max(0, $balance['earned'] - $balance['taken']);
                $sammalone = $employment->salary_form === 'MONTHLY' && ($employment->vacation_rule ?? (string) ($settings['vacation_rule_default'] ?? 'SAMMALONEREGELN')) === 'SAMMALONEREGELN';

                if ($sammalone) {
                    $dayValueBp = BigDecimal::of(str_replace(',', '.', (string) ($settings['vacation_day_value_percent'] ?? '4.6')))->multipliedBy(100)->plus((int) $supplement['bp_of_monthly_salary_per_day']);
                    $dayValue = BigDecimal::of((int) $employment->monthly_salary_ore?->ore)->multipliedBy($employment->employment_rate_bp)->multipliedBy($dayValueBp)->dividedBy(100_000_000, 0, RoundingMode::HalfUp)->toInt();
                } else {
                    $dayValue = $balance['earned'] > 0
                        ? BigDecimal::of($balance['basis_ore'])->multipliedBy($percentBp)->dividedBy(10000 * $balance['earned'], 0, RoundingMode::HalfUp)->toInt()
                        : 0;
                }

                $variableBasis = $sammalone ? (int) ($variable[$employee->id] ?? 0) : 0;

                $result[$employee->id] = [
                    'employee' => $employee,
                    'earned' => $balance['earned'],
                    'taken' => $balance['taken'],
                    'saved_used' => $savedUsed,
                    'left' => $left,
                    'max_save' => $savedUsed > 0 ? 0 : min($left, max(0, $balance['earned'] - $mustTake)),
                    'day_value_ore' => $dayValue,
                    'variable_basis_ore' => $variableBasis,
                    'variable_pay_ore' => BigDecimal::of($variableBasis)->multipliedBy((int) ($supplement['variable_pay_bp'] ?? $percentBp))->dividedBy(10000, 0, RoundingMode::HalfUp)->toInt(),
                ];
            }

            return $result;
        });
    }

    /**
     * @param  array<int|string, int|string|null>  $save  days to save, by employee id
     * @return int the employees closed
     */
    public function __invoke(User $user, Company $company, CarbonImmutable $yearStart, array $save): int
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'payroll:run')) {
            throw new AuthorizationException;
        }

        $settings = $this->modules->settings(PayrollModule::KEY, $company);
        $expected = VacationLedger::years($yearStart, (int) ($settings['vacation_year_start_month'] ?? 4))['year'][0];

        if (! $expected->isSameDay($yearStart)) {
            throw ValidationException::withMessages(['year' => __('Semesteråret börjar :date.', ['date' => $expected->toDateString()])]);
        }

        $yearEnd = $yearStart->addYear()->subDay();

        if (! CarbonImmutable::today()->greaterThan($yearEnd)) {
            throw ValidationException::withMessages(['year' => __('Semesteråret slutar :date och kan stängas först dagen efter.', ['date' => $yearEnd->toDateString()])]);
        }

        return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($user, $company, $yearStart, $yearEnd, $save): int {
            if (VacationYearClosing::query()->whereDate('year_start', $yearStart->toDateString())->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['year' => __('Semesteråret är redan stängt.')]);
            }

            $closed = 0;

            foreach ($this->preview($company, $yearStart) as $employeeId => $row) {
                $days = (int) ($save[$employeeId] ?? 0);

                if ($days < 0 || $days > $row['max_save']) {
                    throw ValidationException::withMessages(["save.{$employeeId}" => __(':name kan spara högst :max dagar.', ['name' => $row['employee']->first_name, 'max' => $row['max_save']])]);
                }

                $paidOut = $row['left'] - $days;

                VacationYearClosing::create([
                    'employee_id' => $employeeId,
                    'year_start' => $yearStart->toDateString(),
                    'year_end' => $yearEnd->toDateString(),
                    'days_earned' => $row['earned'],
                    'days_taken' => $row['taken'],
                    'saved_days_used' => $row['saved_used'],
                    'days_saved' => $days,
                    'days_paid_out' => $paidOut,
                    'payout_ore' => $paidOut * $row['day_value_ore'],
                    'variable_basis_ore' => $row['variable_basis_ore'],
                    'variable_pay_ore' => $row['variable_pay_ore'],
                    'closed_by' => $user->id,
                ]);
                $closed++;
            }

            Audit::record('payroll.vacation_year_closed', null, ['year_start' => $yearStart->toDateString(), 'employees' => $closed], $company, $user);

            return $closed;
        }));
    }

    /**
     * Variable pay due during the year, by employee: additions that are
     * vacation basis (OB and the like), from approved payslips.
     *
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int|string, mixed>
     */
    private function variableBasis(Collection $employees, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DB::table('payslip_lines')
            ->join('payslips', 'payslips.id', '=', 'payslip_lines.payslip_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->join('pay_codes', 'pay_codes.id', '=', 'payslip_lines.pay_code_id')
            ->whereIn('payslips.employee_id', $employees->pluck('id'))
            ->where('pay_codes.category', 'ADDITION')
            ->where('pay_codes.is_vacation_basis', true)
            ->whereNotIn('payroll_runs.status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->whereBetween('payroll_runs.payment_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('payslips.employee_id')
            ->selectRaw('payslips.employee_id, SUM(payslip_lines.amount_ore) AS basis')
            ->pluck('basis', 'payslips.employee_id');
    }
}
