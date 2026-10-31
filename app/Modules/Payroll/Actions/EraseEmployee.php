<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\Models\ImportedPayslip;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\VacationYearClosing;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Erasure of an employee (GDPR art. 17) within Bokföringslagen:
 * - never paid: everything about the person is deleted;
 * - paid, retention running: contact, bank and free-text details are
 *   removed now; name, personal number and tax details stay for corrections
 *   of payslips and AGI until the retention period of the last payslip ends;
 * - retention over: name and personal number are anonymised too.
 *
 * TODO(legal-verify): whether absence records behind paid sick pay are
 * räkenskapsinformation (kept with the payslip) or may be erased earlier.
 */
class EraseEmployee
{
    /** Tables that only hold data about the employee, deleted children first. */
    private const array OWN_TABLES = [
        'salary_changes', 'payroll_inputs', 'recurring_inputs', 'accumulators', 'absences', 'benefits',
        'time_reports', 'vacation_opening_balances', 'expenses', 'employments',
    ];

    /** @return array{mode: 'deleted'|'contact_removed'|'anonymised', kept_until: ?CarbonImmutable} */
    public function __invoke(User $user, Company $company, Employee $employee): array
    {
        Gate::forUser($user)->authorize('manage', [Employee::class, $company]);
        Gate::forUser($user)->authorize('viewSensitive', $employee);

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $employee): array {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if ($employee->status !== 'TERMINATED' && $employee->employments()->exists()) {
                throw ValidationException::withMessages(['erase' => __('Avsluta anställningen innan uppgifterna raderas.')]);
            }

            $inOpenRun = Payslip::query()->where('payslips.employee_id', $employee->id)
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
                ->whereIn('payroll_runs.status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
                ->exists();

            if ($inOpenRun) {
                throw ValidationException::withMessages(['erase' => __('Den anställda finns i en lönekörning som inte är klar. Ta bort den anställda från körningen eller slutför den först.')]);
            }

            $lastPaid = $this->lastPaid($employee);

            if ($lastPaid === null) {
                $this->delete($employee);
                Audit::record('privacy.erased', null, ['subject' => 'employee', 'number' => $employee->employee_number, 'mode' => 'deleted'], $company, $user);

                return ['mode' => 'deleted', 'kept_until' => null];
            }

            $keptUntil = self::keptUntil($company, $lastPaid);
            $retentionOver = $keptUntil->isPast();

            $employee->forceFill([
                'address' => null,
                'postal_code' => null,
                'city' => null,
                'email' => null,
                'phone' => null,
                'emergency_contact' => null,
                'notes' => null,
                'bank_clearing' => null,
                'bank_account' => null,
                'iban' => null,
                'bic' => null,
                'pending_bank' => null,
                'pending_bank_at' => null,
                'user_id' => null,
                ...($retentionOver ? [
                    'first_name' => __('Anonymiserad'),
                    'last_name' => __('anställd :number', ['number' => $employee->employee_number]),
                    'personal_number' => '',
                    'birth_date' => $employee->birth_date->startOfYear(),
                ] : []),
            ])->save();

            $mode = $retentionOver ? 'anonymised' : 'contact_removed';
            Audit::record('privacy.erased', $employee, ['subject' => 'employee', 'mode' => $mode, 'kept_until' => $keptUntil->toDateString()], $company, $user);

            return ['mode' => $mode, 'kept_until' => $retentionOver ? null : $keptUntil];
        }));
    }

    /**
     * The last day the payslips must be kept: seven years after the end of
     * the calendar year in which the fiscal year of the payment ended
     * (Bokföringslagen 7 kap. 2 §).
     */
    public static function keptUntil(Company $company, CarbonImmutable $paid): CarbonImmutable
    {
        $fiscalYearEnd = FiscalYear::query()
            ->where('company_id', $company->id)
            ->whereDate('start_date', '<=', $paid)
            ->whereDate('end_date', '>=', $paid)
            ->value('end_date');

        $endYear = $fiscalYearEnd !== null ? CarbonImmutable::parse((string) $fiscalYearEnd)->year : $paid->year;

        return CarbonImmutable::createFromDate($endYear + 7, 12, 31)->endOfDay();
    }

    private function lastPaid(Employee $employee): ?CarbonImmutable
    {
        $runDate = Payslip::query()
            ->where('payslips.employee_id', $employee->id)
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->whereNotIn('payroll_runs.status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->max('payroll_runs.payment_date');

        $dates = array_filter([
            $runDate,
            // An imported payslip's period (YYYY-MM) is its month of payment.
            ($period = ImportedPayslip::query()->where('employee_id', $employee->id)->max('period')) !== null ? "{$period}-28" : null,
            Expense::query()->where('employee_id', $employee->id)->whereNotNull('voucher_id')->max('date'),
            VacationYearClosing::query()->where('employee_id', $employee->id)->max('created_at'),
        ]);

        return $dates === [] ? null : CarbonImmutable::parse((string) max(array_map(fn ($date): string => CarbonImmutable::parse((string) $date)->toDateString(), $dates)));
    }

    private function delete(Employee $employee): void
    {
        $receipts = Expense::query()->where('employee_id', $employee->id)->whereNotNull('receipt_path')->pluck('receipt_path');

        foreach (self::OWN_TABLES as $table) {
            DB::table($table)->where('employee_id', $employee->id)->delete();
        }

        $employee->delete();

        DB::afterCommit(fn () => Storage::disk('local')->delete($receipts->all()));
    }
}
