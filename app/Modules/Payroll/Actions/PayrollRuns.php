<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\Reports\LedgerQuery;
use App\Domain\Accounting\VoucherSource;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollCalculator;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Domain\SalaryVoucher;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Accumulator;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollFileImportLine;
use App\Modules\Payroll\Models\PayrollInput;
use App\Modules\Payroll\Models\PayrollMigration;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollYearClosing;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Models\SickPayPeriod;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\Models\VacationLiabilitySnapshot;
use App\Modules\Payroll\Models\VacationYearClosing;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Policies\PayrollRunPolicy;
use App\Modules\Payroll\Support\RulePresenter;
use App\Modules\Payroll\Support\Run\ContextBuilder;
use App\Modules\Payroll\Support\Run\PayrollExtensions;
use App\Modules\Payroll\Support\Run\TimeReportPay;
use App\Modules\Payroll\Support\Tax\MissingTaxTableException;
use App\Modules\Payroll\Support\Tax\TaxTableLookup;
use App\Modules\Payroll\Support\Vacation\VacationLedger;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * The payroll run workflow (PAYROLL_MODULE.md B.10): create, add inputs and
 * absence, calculate, reopen and approve. Every action checks that payroll
 * is on, and every change is audited. Calculation either succeeds for every
 * employee or changes nothing and lists what blocks it.
 */
class PayrollRuns
{
    /** Avräkning för skatter och avgifter (skattekonto). */
    public const int TAX_ACCOUNT = 1630;

    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly ContextBuilder $contexts,
        private readonly ModuleManager $modules,
        private readonly TimeReportPay $timeReports,
        private readonly PayrollExtensions $extensions,
    ) {}

    public function create(User $user, Company $company, string $period, ?CarbonImmutable $paymentDate = null): PayrollRun
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);
        Gate::forUser($user)->authorize('create', [PayrollRun::class, $company]);

        $start = CarbonImmutable::createFromFormat('!Y-m', $period)
            ?: throw ValidationException::withMessages(['period' => __('Välj månad.')]);

        return CurrentCompany::run($company, function () use ($user, $company, $period, $start, $paymentDate): PayrollRun {
            if (PayrollRun::query()->where('period', $period)->where('type', 'REGULAR')->exists()) {
                throw ValidationException::withMessages(['period' => __('Det finns redan en lönekörning för :period.', ['period' => $start->translatedFormat('F Y')])]);
            }

            $paymentDate ??= $this->defaultPaymentDate($company, $start);

            if (PayrollYearClosing::isClosed($paymentDate->year)) {
                throw ValidationException::withMessages(['period' => __('Löneåret :year är avslutat. Ingen lön kan betalas ut i det.', ['year' => $paymentDate->year])]);
            }

            $run = PayrollRun::create(['period' => $period, 'payment_date' => $paymentDate]);
            Audit::record('payroll.run_created', $run, ['period' => $period, 'payment_date' => $run->payment_date->toDateString()], $company, $user);

            return $run;
        });
    }

    /**
     * @param  array{employee_id: int|string, pay_code_id: int|string, quantity?: ?string, amount?: ?string, note?: ?string, date_from?: ?string, date_to?: ?string}  $input
     */
    public function addInput(User $user, PayrollRun $run, array $input): PayrollInput
    {
        $company = $this->open($user, $run);

        $validated = Validator::make($input, [
            'employee_id' => ['required', 'integer'],
            'pay_code_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'regex:/^\d{1,6}([.,]\d{1,3})?$/'],
            'amount' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
            // The work date, used by NONOX Avtal to choose the agreement's rate step.
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ], ['quantity.regex' => __('Skriv antal timmar eller enheter, till exempel 12,5.')])->validate();

        if (blank($validated['quantity'] ?? null) === blank($validated['amount'] ?? null)) {
            throw ValidationException::withMessages(['amount' => __('Skriv antingen antal eller belopp.')]);
        }

        try {
            $amount = blank($validated['amount'] ?? null) ? null : Money::parse((string) $validated['amount']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => __('Beloppet går inte att läsa.')]);
        }

        return CurrentCompany::run($company, fn (): PayrollInput => DB::transaction(function () use ($user, $company, $run, $validated, $amount): PayrollInput {
            Employee::query()->findOrFail((int) $validated['employee_id']);
            PayCode::query()->where('is_active', true)->findOrFail((int) $validated['pay_code_id']);

            $input = PayrollInput::create([
                'payroll_run_id' => $run->id,
                'employee_id' => (int) $validated['employee_id'],
                'pay_code_id' => (int) $validated['pay_code_id'],
                'quantity' => blank($validated['quantity'] ?? null) ? null : str_replace(',', '.', (string) $validated['quantity']),
                'amount_ore' => $amount?->ore,
                'note' => $validated['note'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? ($validated['date_from'] ?? null),
                'source' => 'MANUAL',
            ]);
            $this->backToDraft($run);

            Audit::record('payroll.input_added', $run, ['input_id' => $input->id, 'employee_id' => $input->employee_id], $company, $user);

            return $input;
        }));
    }

    public function removeInput(User $user, PayrollRun $run, PayrollInput $input): void
    {
        $company = $this->open($user, $run);

        CurrentCompany::run($company, function () use ($user, $company, $run, $input): void {
            DB::transaction(function () use ($run, $input): void {
                // A line approved from a payroll file goes back to being a proposal.
                PayrollFileImportLine::query()->where('payroll_input_id', $input->id)->get()->each(PayrollFileImports::release(...));
                $input->delete();
                $this->backToDraft($run);
            });

            Audit::record('payroll.input_removed', $run, ['input_id' => $input->id], $company, $user);
        });
    }

    /**
     * Records approved absence for an employee: sick days, leave and other
     * types, with the scheduled hours missed per day.
     *
     * @param  array{employee_id: int|string, type: string, date_from: string, date_to: string, hours: string}  $input
     */
    public function recordAbsence(User $user, PayrollRun $run, array $input): Absence
    {
        $company = $this->open($user, $run);
        $first = $run->periodStart()->toDateString();
        $last = $run->periodEnd()->toDateString();
        // Absence belongs to the run for the month it happened in; a period over a month end is entered in each month's run.
        $outside = __('Frånvaron måste ligga i lönekörningens månad, :from–:to. Frånvaro över ett månadsskifte registreras i varje månads lönekörning.', ['from' => $first, 'to' => $last]);

        $validated = Validator::make($input, [
            'employee_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(['SICK', 'VAB', 'PARENTAL', 'VACATION', 'LEAVE_UNPAID', 'LEAVE_PAID', 'STUDY', 'MILITARY', 'OTHER'])],
            'date_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$first, 'before_or_equal:'.$last],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:'.$last],
            'hours' => ['required', 'regex:/^\d{1,2}([.,]\d{1,2})?$/'],
        ], [
            'hours.regex' => __('Skriv timmar per dag, till exempel 8 eller 7,5.'),
            'date_from.after_or_equal' => $outside,
            'date_from.before_or_equal' => $outside,
            'date_to.before_or_equal' => $outside,
        ], ['date_from' => __('första dagen'), 'date_to' => __('sista dagen')])->validate();

        return CurrentCompany::run($company, fn (): Absence => DB::transaction(function () use ($user, $company, $run, $validated): Absence {
            Employee::query()->findOrFail((int) $validated['employee_id']);

            $absence = Absence::create([
                'employee_id' => (int) $validated['employee_id'],
                'type' => $validated['type'],
                'date_from' => $validated['date_from'],
                'date_to' => $validated['date_to'],
                'hours' => str_replace(',', '.', (string) $validated['hours']),
                'status' => 'APPROVED',
                'approved_by' => $user->id,
            ]);
            $this->backToDraft($run);

            // The type is sensitive; the audit log records that absence was registered, not why.
            Audit::record('payroll.absence_recorded', $absence, ['employee_id' => $absence->employee_id], $company, $user);

            return $absence;
        }));
    }

    /**
     * Calculates every employment in the period. Either every payslip is
     * calculated and stored, or nothing changes and the blockers are thrown.
     *
     * @throws ValidationException listing what blocks the run
     */
    public function calculate(User $user, PayrollRun $run): PayrollRun
    {
        $company = $this->open($user, $run);

        return CurrentCompany::run($company, fn (): PayrollRun => DB::transaction(function () use ($user, $company, $run): PayrollRun {
            /** @var PayrollRun $run */
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            // Year-to-date totals from the earlier system must be in place before the first run (PAYROLL_MODULE.md B.16).
            if (PayrollMigration::inProgress()) {
                throw ValidationException::withMessages(['run' => [__('Flytten av lönedata från det tidigare lönesystemet är inte klar. Gör klart den under Lön → Flytt av lönedata innan lönen räknas.')]]);
            }

            $rules = RuleSet::for($run->payment_date);
            $settings = $this->modules->settings(PayrollModule::KEY, $company);
            app(SeedPayCodes::class)($company);
            $payCodes = PayCode::query()->get()->keyBy('id');
            $codeIds = $payCodes->pluck('id', 'code');
            $employments = Employment::query()
                ->with('employee')
                ->whereDate('start_date', '<=', $run->periodEnd()->toDateString())
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $run->periodStart()->toDateString()))
                ->get();
            $inputs = PayrollInput::query()->where('payroll_run_id', $run->id)->get()->groupBy('employee_id');
            // Reports from earlier months only when the person running payroll chose to include them.
            $timeReports = $this->timeReports->claimable($run, $employments->pluck('employee_id'), $run->type === 'REGULAR' ? ($run->late_time_reports_included ?? []) : []);
            $start = $run->periodStart();
            $lateReports = $timeReports->filter(fn (TimeReport $report): bool => $report->date->lessThan($start))->values();
            $timeReports = $timeReports->filter(fn (TimeReport $report): bool => ! $report->date->lessThan($start))->values();
            $lateEmployments = $lateReports->isEmpty()
                ? collect()
                : Employment::query()->whereIn('employee_id', $lateReports->pluck('employee_id')->unique()->values())->where('salary_form', 'HOURLY')->get();
            // The late hours go on one payslip per employee: the employment that started last in the run.
            $latePayslip = $employments->sortBy(fn (Employment $employment): string => $employment->start_date->toDateString().'#'.str_pad((string) $employment->id, 12, '0', STR_PAD_LEFT))
                ->mapWithKeys(fn (Employment $employment): array => [$employment->employee_id => $employment->id]);
            $claimed = [];
            $results = [];
            $blockers = [];
            $rulesMissing = false;

            if ($employments->isEmpty()) {
                throw ValidationException::withMessages(['run' => [__('Ingen är anställd under perioden.')]]);
            }

            // The tax tables follow the payment date's year (SFL 12 kap. 1 §: tables per calendar year), so December's pay paid in January needs the new year's tables.
            // TODO(legal-verify): SFL 11 kap. 16 § andra stycket lets January's withholding use the table last applied the year before; no such fallback is built, the run is blocked.
            if (! TaxTableLookup::imported($run->payment_date->year) && $employments->contains(fn (Employment $employment): bool => $employment->employee?->tax_table !== null && ! str_starts_with((string) $employment->employee->tax_residence, 'NON_RESIDENT'))) {
                $blockers[] = (new MissingTaxTableException($run->payment_date->year, 0, 0, true))->getMessage();
            }

            $this->extensions->prepare($run, $employments);

            foreach ($employments as $employment) {
                $employee = $employment->employee ?? throw new LogicException("Employment {$employment->id} has no employee.");
                $name = "{$employee->first_name} {$employee->last_name}";

                // One report is paid once, also when the employee has two employments in the month.
                $reports = TimeReportPay::forEmployment($timeReports, $employment, $codeIds->get(TimeReportPay::PAY_CODE))
                    ->reject(fn (TimeReport $report): bool => in_array($report->id, $claimed, true))
                    ->values();
                $claimed = [...$claimed, ...array_map(fn (TimeReport $report): int => $report->id, $reports->all())];
                $late = $latePayslip->get($employee->id) === $employment->id
                    ? TimeReportPay::lateGroups($lateReports->where('employee_id', $employee->id)->values(), $lateEmployments)
                    : [];

                foreach ($late as $group) {
                    $claimed = [...$claimed, ...array_map(fn (TimeReport $report): int => $report->id, $group['reports']->all())];
                }

                try {
                    $context = $this->contexts->build($run, $employee, $employment, $inputs->get($employee->id, collect()), $payCodes, $settings, $rules, $reports, $late);
                    $results[] = [$employment, $this->calculator->calculate($context)];
                } catch (MissingTaxTableException $exception) {
                    // Without the year's tables at all, one line says so for the whole run.
                    $blockers[] = $exception->yearMissing ? $exception->getMessage() : "{$name}: {$exception->getMessage()}";
                } catch (PayrollBlockedException $exception) {
                    $blockers[] = "{$name}: {$exception->getMessage()}";
                } catch (MissingPayrollRuleException) {
                    $rulesMissing = true;
                }
            }

            foreach ($results as [$employment, $result]) {
                foreach ($result->lines as $line) {
                    if (! $codeIds->has($line->code)) {
                        $blockers[] = __('Lönearten :code finns inte. Slå av och på lönemodulen för att lägga till systemets lönearter.', ['code' => $line->code]);
                    }
                }
            }

            if ($rulesMissing) {
                $missing = $rules->missing();
                array_unshift($blockers, __(':count regler är inte kontrollerade för utbetalningsdagen :date: :rules. Öppna Lön → Regler, jämför varje värde med källan och markera det som kontrollerat.', [
                    'count' => count($missing),
                    'date' => $run->payment_date->toDateString(),
                    'rules' => implode(', ', array_map(RulePresenter::name(...), $missing)),
                ]));
            }

            if ($blockers !== []) {
                throw ValidationException::withMessages(array_filter([
                    'run' => array_values(array_unique($blockers)),
                    'rules' => $rulesMissing ? [$run->payment_date->toDateString()] : [],
                ]));
            }

            $this->deletePayslips($run);
            $this->claimTimeReports($run, $claimed);

            foreach ($results as [$employment, $result]) {
                $payslip = Payslip::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employment->employee_id,
                    'employment_id' => $employment->id,
                    'gross_ore' => $result->gross(),
                    'taxable_ore' => $result->taxableRegular() + $result->taxableOneOff(),
                    'tax_ore' => $result->taxOre,
                    'net_ore' => $result->netOre,
                    'contribution_basis_ore' => $result->contributionBasis(),
                    'employer_contribution_ore' => $result->employerContributionOre,
                    'accumulator_deltas' => $result->accumulatorDeltas,
                    'tax_explanation' => $result->taxExplanation,
                    'contribution_explanation' => $result->contributionExplanation,
                    'warnings' => $result->warnings,
                ]);

                foreach ($result->sickPeriods as $period) {
                    SickPayPeriod::create([...$period, 'payroll_run_id' => $run->id, 'payslip_id' => $payslip->id, 'employee_id' => $employment->employee_id]);
                }

                foreach ($result->lines as $line) {
                    PayslipLine::create([
                        'payslip_id' => $payslip->id,
                        'pay_code_id' => (int) $codeIds[$line->code],
                        'description' => $line->description,
                        'quantity' => $line->quantity,
                        'rate_ore' => $line->rateOre,
                        'amount_ore' => $line->amountOre,
                        'tax_effect' => $line->taxEffect,
                        'rule_version_id' => $line->ruleVersionId,
                        'explanation' => $line->explanation,
                    ]);
                }
            }

            $run->forceFill([
                'status' => PayrollRun::CALCULATED,
                'calculated_by' => $user->id,
                'calculated_at' => now(),
                'rule_snapshot_hash' => $rules->snapshotHash(),
            ])->save();

            Audit::record('payroll.run_calculated', $run, ['period' => $run->period, 'payslips' => count($results), 'time_reports' => count($claimed), 'rule_snapshot_hash' => $run->rule_snapshot_hash], $company, $user);

            return $run;
        }));
    }

    /**
     * The answer to the run's question about approved hours from earlier
     * months that no run has paid: include them in this run, or not now.
     * The answer covers the reports waiting when it is given and is kept on
     * the run, so a recalculation respects it; reports that turn up later
     * are asked about again. Including makes the calculation out of date.
     *
     * @return int how many time reports the answer covers
     */
    public function decideLateTimeReports(User $user, PayrollRun $run, bool $include): int
    {
        $company = $this->open($user, $run);

        return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($user, $company, $run, $include): int {
            /** @var PayrollRun $run */
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if (! $run->isOpen()) {
                throw new AuthorizationException(__('Lönekörningen är attesterad och kan inte ändras.'));
            }

            $waiting = $this->timeReports->late($run);

            if ($waiting->isEmpty()) {
                throw ValidationException::withMessages(['late_time_reports' => __('Det finns inga timmar från tidigare månader att ta ställning till.')]);
            }

            $ids = array_values(array_map(fn (TimeReport $report): int => $report->id, $waiting->all()));
            $column = $include ? 'late_time_reports_included' : 'late_time_reports_skipped';
            $run->forceFill([$column => [...($run->{$column} ?? []), ...$ids]])->save();

            if ($include) {
                $this->backToDraft($run);
            }

            Audit::record($include ? 'payroll.late_time_reports_included' : 'payroll.late_time_reports_skipped', $run, [
                'time_report_ids' => $ids,
                'hours' => (string) TimeReportPay::hours($waiting),
            ], $company, $user);

            return count($ids);
        }));
    }

    /**
     * Takes back the answer about hours from earlier months while the run is
     * open, so the question is asked again. Reports that were included are
     * released, which makes the calculation out of date.
     */
    public function resetLateTimeReports(User $user, PayrollRun $run): PayrollRun
    {
        $company = $this->open($user, $run);

        return CurrentCompany::run($company, fn (): PayrollRun => DB::transaction(function () use ($user, $company, $run): PayrollRun {
            /** @var PayrollRun $run */
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if (! $run->isOpen()) {
                throw new AuthorizationException(__('Lönekörningen är attesterad och kan inte ändras.'));
            }

            $included = $run->late_time_reports_included ?? [];
            $previous = ['included' => $included, 'skipped' => $run->late_time_reports_skipped ?? []];
            $run->forceFill(['late_time_reports_included' => null, 'late_time_reports_skipped' => null])->save();

            if ($included !== []) {
                $this->backToDraft($run);
            }

            Audit::record('payroll.late_time_reports_reset', $run, $previous, $company, $user);

            return $run;
        }));
    }

    public function reopen(User $user, PayrollRun $run): PayrollRun
    {
        $company = $this->open($user, $run);

        return CurrentCompany::run($company, fn (): PayrollRun => DB::transaction(function () use ($user, $company, $run): PayrollRun {
            $this->backToDraft($run);
            Audit::record('payroll.run_reopened', $run, ['period' => $run->period], $company, $user);

            return $run;
        }));
    }

    public function approve(User $user, PayrollRun $run): PayrollRun
    {
        $company = Company::query()->findOrFail($run->company_id);
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if ((new PayrollRunPolicy)->blockedBySegregation($user, $run)) {
            throw ValidationException::withMessages(['run' => [__('Du beräknade lönekörningen och kan därför inte attestera den. Någon annan med rätt att attestera behöver göra det. Är du ensam i företaget kan ägaren slå av kravet under Företagsinställningar → Moduler → Lön → Inställningar.')]]);
        }

        Gate::forUser($user)->authorize('approve', $run);

        return CurrentCompany::run($company, fn (): PayrollRun => DB::transaction(function () use ($user, $company, $run): PayrollRun {
            /** @var PayrollRun $run */
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($run->status !== PayrollRun::CALCULATED) {
                throw ValidationException::withMessages(['run' => [__('Lönekörningen är inte beräknad.')]]);
            }

            foreach (Payslip::query()->where('payroll_run_id', $run->id)->get() as $payslip) {
                foreach ($payslip->accumulator_deltas as $key => $delta) {
                    $accumulator = Accumulator::query()->firstOrNew(['employee_id' => $payslip->employee_id, 'scope' => 'CALENDAR_YEAR', 'year' => $run->payment_date->year, 'key' => $key]);
                    $accumulator->forceFill(['value' => (int) $accumulator->value + (int) $delta])->save();
                }
            }

            // Vacation pay from a closed semesterår is paid once: the run that carries it claims it.
            $closingIds = PayslipLine::query()
                ->whereIn('payslip_id', Payslip::query()->where('payroll_run_id', $run->id)->select('id'))
                ->whereRaw("explanation->>'vacation_year_closing_id' IS NOT NULL")
                ->pluck('explanation')
                ->map(fn (array $explanation): int => (int) $explanation['vacation_year_closing_id'])
                ->unique()
                ->all();
            VacationYearClosing::query()->whereIn('id', $closingIds)->whereNull('payroll_run_id')->update(['payroll_run_id' => $run->id]);

            $run->forceFill(['status' => PayrollRun::APPROVED, 'approved_by' => $user->id, 'approved_at' => now()])->save();
            Audit::record('payroll.run_approved', $run, ['period' => $run->period, 'rule_snapshot_hash' => $run->rule_snapshot_hash], $company, $user);

            return $run;
        }));
    }

    /**
     * Records that the bank has paid the net salaries of an approved run.
     */
    public function markPaid(User $user, PayrollRun $run): PayrollRun
    {
        $company = Company::query()->findOrFail($run->company_id);
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);
        Gate::forUser($user)->authorize('pay', $run);

        return CurrentCompany::run($company, function () use ($user, $company, $run): PayrollRun {
            $run->forceFill(['status' => PayrollRun::PAID])->save();
            Expense::query()->where('payroll_run_id', $run->id)->where('status', 'APPROVED')->update(['status' => 'PAID']);
            Audit::record('payroll.run_paid', $run, ['period' => $run->period], $company, $user);

            return $run;
        });
    }

    /**
     * Books a paid run as a salary voucher in series L on the payment date.
     */
    public function post(User $user, PayrollRun $run): PayrollRun
    {
        $company = Company::query()->findOrFail($run->company_id);
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);
        Gate::forUser($user)->authorize('post', $run);

        return CurrentCompany::run($company, fn (): PayrollRun => DB::transaction(function () use ($user, $company, $run): PayrollRun {
            /** @var PayrollRun $run */
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($run->status !== PayrollRun::PAID) {
                throw ValidationException::withMessages(['run' => [__('Lönekörningen är inte markerad som utbetald.')]]);
            }

            $payslips = Payslip::query()->with(['lines', 'employment.costCenter'])->where('payroll_run_id', $run->id)->get();
            $payCodes = PayCode::query()->get()->keyBy('id');
            $accounts = Account::query()->whereIn('id', $payCodes->pluck('debit_account_id')->merge($payCodes->pluck('credit_account_id'))->filter()->unique())->get()->keyBy('id');

            $salaryRows = SalaryVoucher::rows($payslips, $payCodes, $accounts);
            $liability = $this->vacationLiability($company, $run);

            $voucher = app(BookVoucher::class)->rows(
                $user,
                $company,
                $run->payment_date,
                $run->title(),
                'L',
                [...$salaryRows, ...($liability['rows'] ?? [])],
                VoucherSource::Payroll,
                $run,
            );

            if ($liability !== null) {
                VacationLiabilitySnapshot::create($liability['snapshot']);
            }

            $run->forceFill(['status' => PayrollRun::POSTED, 'posted_voucher_id' => $voucher->id])->save();
            Audit::record('payroll.run_posted', $run, ['period' => $run->period, 'voucher' => $voucher->reference()], $company, $user);

            return $run;
        }));
    }

    /**
     * Books the run's withheld tax (2710) and its contributions and special
     * payroll tax (2730) against the tax account, on the day Skatteverket
     * draws them (PAYROLL_MODULE.md B.12). Once per run.
     */
    public function postTax(User $user, PayrollRun $run, CarbonImmutable $date): PayrollRun
    {
        $company = Company::query()->findOrFail($run->company_id);
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'payroll:post')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, fn (): PayrollRun => DB::transaction(function () use ($user, $company, $run, $date): PayrollRun {
            /** @var PayrollRun $run */
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if ($run->status !== PayrollRun::POSTED || $run->tax_voucher_id !== null) {
                throw ValidationException::withMessages(['run' => [__('Skatten kan bokföras när lönen är bokförd, och bara en gång.')]]);
            }

            $payslipIds = Payslip::query()->where('payroll_run_id', $run->id)->pluck('id');
            $tax = (int) Payslip::query()->whereIn('id', $payslipIds)->sum('tax_ore');
            // What the arbetsgivardeklaration debits: withheld tax and employer contributions. Särskild löneskatt
            // on pension is paid through the company's preliminary tax and stays on 2514 until it is.
            // TODO(legal-verify): how särskild löneskatt on pension costs reaches the tax account.
            $contributions = (int) Payslip::query()->whereIn('id', $payslipIds)->sum('employer_contribution_ore');

            $voucher = app(BookVoucher::class)->rows($user, $company, $date, __('Skatt och avgifter, :title', ['title' => $run->title()]), 'L', array_values(array_filter([
                $tax > 0 ? ['account' => SalaryVoucher::TAX, 'amount' => $tax, 'description' => __('Personalskatt')] : null,
                $contributions > 0 ? ['account' => SalaryVoucher::CONTRIBUTIONS_LIABILITY, 'amount' => $contributions, 'description' => __('Arbetsgivaravgifter')] : null,
                ['account' => self::TAX_ACCOUNT, 'amount' => -($tax + $contributions), 'description' => __('Skattekontot')],
            ])), VoucherSource::Payroll, $run);

            $run->forceFill(['tax_voucher_id' => $voucher->id])->save();
            Audit::record('payroll.tax_posted', $run, ['voucher' => $voucher->reference(), 'tax_ore' => $tax, 'contributions_ore' => $contributions], $company, $user);

            return $run;
        }));
    }

    /**
     * The vacation liability at the end of the run's period and the voucher
     * rows that bring 2920 and 2940 to it, when the company books it monthly
     * (PAYROLL_MODULE.md B.12). Null when booked at year end.
     *
     * @return ?array{rows: list<array{account: int, amount: int, description: string}>, snapshot: array<string, mixed>}
     */
    private function vacationLiability(Company $company, PayrollRun $run): ?array
    {
        $settings = $this->modules->settings(PayrollModule::KEY, $company);

        if (($settings['vacation_liability_booking'] ?? 'MONTHLY') !== 'MONTHLY') {
            return null;
        }

        $day = $run->periodEnd();
        $employments = Employment::query()
            ->whereDate('start_date', '<=', $day->toDateString())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $day->toDateString()))
            ->orderBy('start_date')
            ->get()
            ->keyBy('employee_id');
        $employees = Employee::query()->whereIn('id', $employments->keys())->get();
        $values = app(VacationLedger::class)->liability($employees, $employments, $day, $settings, RuleSet::for($run->payment_date));
        $liability = array_sum(array_column($values, 'liability_ore'));
        $contributions = array_sum(array_column($values, 'contributions_ore'));
        $needed = [SalaryVoucher::VACATION_LIABILITY_CHANGE, SalaryVoucher::VACATION_LIABILITY, SalaryVoucher::VACATION_CONTRIBUTIONS_CHANGE, SalaryVoucher::VACATION_CONTRIBUTIONS_LIABILITY];
        $accounts = Account::query()->whereIn('number', $needed)->pluck('id', 'number');
        $missing = array_diff($needed, $accounts->keys()->all());

        if ($missing !== []) {
            throw ValidationException::withMessages(['run' => [__('Konto :accounts för semesterlöneskulden saknas. Lägg till kontona under Lön → Kom igång.', ['accounts' => implode(', ', $missing)])]]);
        }

        // The change is measured against what the ledger already holds on 2920 and 2940, so opening balances and manual entries count.
        $fiscalYear = FiscalYear::query()->whereDate('start_date', '<=', $run->payment_date->toDateString())->whereDate('end_date', '>=', $run->payment_date->toDateString())->first();
        $balances = $fiscalYear === null ? collect() : (new LedgerQuery($fiscalYear, $run->payment_date->addDay(), $run->payment_date))->openingBalances();
        $booked = fn (int $number): int => -(int) ($balances[$accounts[$number]] ?? 0);
        $rows = SalaryVoucher::vacationLiabilityRows($liability - $booked(SalaryVoucher::VACATION_LIABILITY), $contributions - $booked(SalaryVoucher::VACATION_CONTRIBUTIONS_LIABILITY));

        return [
            'rows' => $rows,
            'snapshot' => ['payroll_run_id' => $run->id, 'as_of' => $day->toDateString(), 'liability_ore' => $liability, 'contributions_ore' => $contributions, 'employees' => $values],
        ];
    }

    private function open(User $user, PayrollRun $run): Company
    {
        $company = Company::query()->findOrFail($run->company_id);
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);
        Gate::forUser($user)->authorize('update', $run);

        return $company;
    }

    /**
     * The run pays exactly these time reports: the ones it claimed before
     * are released first, so a recalculation never pays a report twice and
     * one that no longer belongs to the run is free for the next. The
     * reports were locked when they were read; a report another run claimed
     * in between stops the calculation.
     *
     * @param  list<int>  $ids
     */
    private function claimTimeReports(PayrollRun $run, array $ids): void
    {
        TimeReport::query()->where('payroll_run_id', $run->id)->update(['payroll_run_id' => null]);

        $updated = TimeReport::query()
            ->whereIn('id', $ids)
            ->whereNull('payroll_run_id')
            ->where('status', 'APPROVED')
            ->update(['payroll_run_id' => $run->id]);

        if ($updated !== count($ids)) {
            throw ValidationException::withMessages(['run' => [__('Tidrapporterna ändrades medan lönen räknades. Beräkna lönekörningen igen.')]]);
        }
    }

    /** A change to inputs or absence makes the calculation out of date. */
    public function backToDraft(PayrollRun $run): void
    {
        $this->deletePayslips($run);
        TimeReport::query()->where('payroll_run_id', $run->id)->update(['payroll_run_id' => null]);
        $run->forceFill(['status' => PayrollRun::DRAFT, 'calculated_by' => null, 'calculated_at' => null, 'rule_snapshot_hash' => null])->save();
    }

    private function deletePayslips(PayrollRun $run): void
    {
        $ids = Payslip::query()->where('payroll_run_id', $run->id)->pluck('id');
        SickPayPeriod::query()->where('payroll_run_id', $run->id)->delete();
        PayslipLine::query()->whereIn('payslip_id', $ids)->delete();
        Payslip::query()->whereIn('id', $ids)->delete();
    }

    /**
     * The company's payment day in the month, moved back to Friday when it
     * falls on a weekend. TODO: public holidays (holiday calendar SE).
     */
    private function defaultPaymentDate(Company $company, CarbonImmutable $month): CarbonImmutable
    {
        $day = (int) ($this->modules->settings(PayrollModule::KEY, $company)['default_payment_day'] ?? 25);
        $date = $month->setDay(min($day, $month->daysInMonth));

        while ($date->isWeekend()) {
            $date = $date->subDay();
        }

        return $date;
    }
}
