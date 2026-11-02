<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Company\UpdateCompanyDetails;
use App\Actions\Payables\SavePaymentBankSettings;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\AddPayrollAccounts;
use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\ChangeSalary;
use App\Modules\Payroll\Actions\CloseVacationYear;
use App\Modules\Payroll\Actions\EndEmployment;
use App\Modules\Payroll\Actions\MovePayrollData;
use App\Modules\Payroll\Actions\PayrollFileImports;
use App\Modules\Payroll\Actions\PayrollImportSources;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Actions\SalaryPaymentFile;
use App\Modules\Payroll\Actions\SaveEmployee;
use App\Modules\Payroll\Actions\SavePensionPlan;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\Support\Tax\TaxTableImporter;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\AddsTeamMembers;

/**
 * Real payroll runs for the restaurant, January to October, through the
 * payroll module's own Actions: calculated by the owner, approved by a
 * payroll administrator, paid with a salary file, booked in series L, the
 * tax drawn on the 12th and the arbetsgivardeklaration generated, filed and
 * accepted. Along the way: the move from the earlier payroll system with
 * vacation balances, a pension plan, staff meals and mileage, a sick period
 * with karensavdrag, summer vacation, the closing of the vacation year, a
 * salary revision (one of them retroactive), hourly pay from a scheduling
 * system's payroll file, and an employee who leaves in July. November's run
 * is left open.
 *
 * Payroll rules: only for this demo database, the product owner has decided
 * that the rule values the test suite uses (tests/Support/Payroll.php) and
 * Skatteverket's 2026 table 33 fixture are stored and marked as verified by
 * "Demodata – inte kontrollerad". They are not checked against any source
 * and must never be used outside the demo seeder.
 */
final class PayrollExtras extends DemoExtra
{
    public const array APPROVER = ['name' => 'Karin Holm', 'email' => 'karin.holm@example.test'];

    public const string VERIFIED_BY = 'Demodata – inte kontrollerad';

    private const string SOURCE_URL = 'https://example.test/demodata';

    /** The test suite's rule values (tests/Support/Payroll.php and AgiTest), copied: demo only, not verified. */
    private const array RULES = [
        'employer_contribution.full' => ['bp' => 3142],
        'employer_contribution.reduced_by_birthyear' => ['bands' => [['born_to' => 1937, 'bp' => 0], ['born_from' => 1938, 'born_to' => 1958, 'bp' => 1021]]],
        'employer_contribution.youth_reduction' => ['applies' => true, 'born_from' => 2003, 'born_to' => 2007, 'bp' => 2081, 'monthly_cap_ore' => 2_500_000],
        'employer_contribution.min_annual_basis' => ['ore' => 100_000],
        'sink.rate' => ['bp' => 2250],
        'tax.rounding' => ['mode' => 'DOWN_TO_KRONA'],
        'sick_pay.percentage' => ['bp' => 8000],
        'sick_pay.karensavdrag_percent_of_weekly' => ['bp' => 2000, 'max_occasions_per_12_months' => 10],
        'sick_pay.employer_period_days' => ['days' => 14],
        'sick_pay.reinfection_window_days' => ['days' => 5],
        'a_sink.rate' => ['bp' => 1500],
        'tax.one_off_without_regular_salary' => ['bp' => 3000],
        'tax.one_off' => ['columns' => ['1' => [[0, 25041, 0], [25042, 82800, 1000], [82801, 192000, 2100], [192001, 477600, 2600], [477601, 660000, 3400], [660001, null, 5400]], '2' => [[0, 65800, 0], [65801, 477600, 2600], [477601, 660000, 3400], [660001, null, 5500]], '3' => [[0, 25041, 0], [25042, 331200, 1000], [331201, 477600, 2600], [477601, 660000, 3400], [660001, null, 5500]], '4' => [[0, 25041, 0], [25042, 54000, 300], [54001, 192000, 2200], [192001, 660000, 2600], [660001, null, 4600]], '5' => [[0, 25041, 0], [25042, 32400, 1000], [32401, 160800, 2900], [160801, 184800, 3400], [184801, 660000, 3800], [660001, null, 5400]], '6' => [[0, 25041, 0], [25042, 160800, 2900], [160801, 184800, 3400], [184801, 660000, 3800], [660001, null, 5400]]], 'note' => '[from, to, rate bp] on the expected annual income in whole kronor (SKV 433 avsnitt 10).'],
        'benefit.meal.free_day' => ['ore' => 31000],
        'benefit.meal.lunch_or_dinner' => ['ore' => 12400],
        'benefit.meal.breakfast' => ['ore' => 6200],
        'mileage.own_car_tax_free_per_mil' => ['ore' => 2500],
        'per_diem.domestic.full' => ['ore' => 30000, 'after_three_months_ore' => 21000, 'after_two_years_ore' => 15000],
        'per_diem.domestic.half' => ['ore' => 15000],
        'per_diem.domestic.night' => ['ore' => 15000],
        'inkomstbasbelopp' => ['ore' => 8340000],
        'special_payroll_tax.pension' => ['bp' => 2426],
        'vacation.minimum_days' => ['days' => 25],
        'vacation.supplement_sammaloneregeln' => ['bp_of_monthly_salary_per_day' => 43, 'weekly_bp' => 182, 'variable_pay_bp' => 1200],
        'vacation.percent_rule' => ['bp' => 1200],
        'vacation.max_saved_days' => ['days_that_must_be_taken' => 20],
        'vacation.qualifying_absence_days' => ['SICK' => 180, 'PARENTAL' => 120, 'VAB' => 120, 'MILITARY' => 60],
        'agi.schema_version' => ['version' => '1.1'],
        'agi.deadlines' => ['turnover_up_to_40_msek' => ['2026-01-19', '2026-05-12'], 'turnover_over_40_msek_declare_day' => 26],
    ];

    private const string TAX_TABLE = 'tests/Fixtures/payroll/skattetabell-2026-manad-tabell-33.txt';

    /** The month whose hourly pay comes from the scheduling system's file instead of typed hours. */
    private const int FILE_MONTH = 9;

    public function __construct(
        private readonly PayrollRuns $runs,
    ) {}

    public function industries(): array
    {
        return ['bokforing-for-restauranger'];
    }

    public function schedule(DemoContext $context): void
    {
        // Nothing in the demo may send real mail: invitations, absence and outlay notices go to the log.
        config(['mail.default' => 'log']);

        self::rules();
        self::taxTable();
        $this->approver($context);
        app(AddPayrollAccounts::class)($context->user, $context->company);
        $this->companyContact($context);

        $start = $context->start;
        $context->on($start->addDay(), 5, fn () => $this->movePayrollData($context));
        $context->on($start->addDay(), 6, fn () => $this->pensionPlan($context));
        $context->on(CarbonImmutable::parse('2026-03-16'), 5, fn () => app(ChangeSalary::class)($context->user, $context->company, $this->employeeOrFail($context, 'Sara'), '2026-04-01', '38 000', 'Lönerevision 2026'));
        $context->on(CarbonImmutable::parse('2026-04-02'), 5, fn () => $this->closeVacationYear($context));
        // Agreed in May, valid from April: the May run pays April's difference as retroactive pay.
        $context->on(CarbonImmutable::parse('2026-05-06'), 5, fn () => app(ChangeSalary::class)($context->user, $context->company, $this->employeeOrFail($context, 'Ali'), '2026-04-01', '31 200', 'Lönerevision 2026, retroaktiv från april'));
        $context->on(CarbonImmutable::parse('2026-07-10'), 5, fn () => app(EndEmployment::class)($context->user, $context->company, $this->employeeOrFail($context, 'Omar'), '2026-07-31', 'Egen uppsägning, börjar studera i höst.'));

        for ($month = 1; $month <= 10; $month++) {
            $period = sprintf('2026-%02d', $month);
            $payday = self::payday($period);

            if ($payday > $context->end) {
                continue;
            }

            $context->on($payday, 60, fn () => $this->payrollRun($context, $period));

            $filed = $payday->startOfMonth()->addMonthNoOverflow()->day(5);
            $drawn = $payday->startOfMonth()->addMonthNoOverflow()->day(12);

            if ($drawn <= $context->end) {
                $context->on($filed, 60, fn () => $this->declare($context, $period, true));
                $context->on($drawn, 60, fn () => $this->postTax($context, $period, $drawn));
            } elseif ($context->end >= $payday) {
                // The latest month's declaration is generated but not yet filed.
                $context->on($context->end, 60, fn () => $this->declare($context, $period, false));
            }
        }

        $context->on($context->end, 90, fn () => $this->openRun($context, $context->today->startOfMonth()));
    }

    /** Payday as the module sets it: the 25th, moved back to Friday over a weekend. */
    public static function payday(string $period): CarbonImmutable
    {
        $date = CarbonImmutable::parse("{$period}-25");

        while ($date->isWeekend()) {
            $date = $date->subDay();
        }

        return $date;
    }

    public static function approverUser(): ?User
    {
        return User::query()->where('email', self::APPROVER['email'])->first();
    }

    /** The demo's rule versions, marked as verified by the demo itself: see the class comment. */
    private static function rules(): void
    {
        foreach (self::RULES as $key => $value) {
            $version = PayrollRuleVersion::query()->where('key', $key)->whereDate('valid_from', '2026-01-01')->first()
                ?? PayrollRuleVersion::create(['key' => $key, 'valid_from' => '2026-01-01', 'value' => $value, 'source_url' => self::SOURCE_URL, 'source_quote' => self::VERIFIED_BY]);

            if (! $version->isVerified()) {
                $version->forceFill(['verified_by' => self::VERIFIED_BY, 'verified_at' => now()])->save();
            }
        }
    }

    private static function taxTable(): void
    {
        if (DB::table('tax_table_imports')->where('year', 2026)->exists()) {
            return;
        }

        app(TaxTableImporter::class)->import(2026, basename(self::TAX_TABLE), (string) file_get_contents(base_path(self::TAX_TABLE)));
    }

    /** A payroll administrator who approves the runs the owner calculates (segregation of duties). */
    private function approver(DemoContext $context): void
    {
        $user = User::query()->firstOrCreate(['email' => self::APPROVER['email']], ['name' => self::APPROVER['name'], 'email_verified_at' => now()]);

        if (! $context->company->hasUser($user)) {
            app(AddsTeamMembers::class)->add($context->user, $context->company, $user->email, 'payroll_admin');
        }
    }

    /** Skatteverket wants a phone number to the contact on the arbetsgivardeklaration; the salary file the bank. */
    private function companyContact(DemoContext $context): void
    {
        $company = $context->company->fresh() ?? $context->company;

        if (blank($company->phone)) {
            app(UpdateCompanyDetails::class)($context->user, $company, [
                'name' => $company->name,
                'address_line1' => $company->address_line1,
                'postal_code' => $company->postal_code,
                'city' => $company->city,
                'email' => $company->email,
                'phone' => '031-123 45 67',
                'website' => $company->website,
                'bankgiro' => $company->bankgiro,
                'plusgiro' => $company->plusgiro,
                'vat_period' => $company->vat_period->value,
                'fiscal_year_start_month' => $company->fiscal_year_start_month,
                'accounting_method' => $company->accounting_method->value,
                'reporting_framework' => $company->reporting_framework->value,
                'f_skatt' => (bool) $company->f_skatt,
                'employer_registered' => true,
            ]);
        }

        $company = $company->fresh() ?? $company;

        if ($company->payment_bank === null) {
            app(SavePaymentBankSettings::class)($context->user, $company, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE45 5000 0000 0583 9825 7466', 'bank_agreement_id' => 'DEMO-'.$company->id]);
        }

        $context->company->refresh();
    }

    /**
     * The move from the earlier payroll system on the first working day:
     * the staff with e-mail and salary accounts, this year's totals (zero,
     * nothing is paid yet) and the vacation balances of the running
     * semesterår, then completed against the bookkeeping.
     */
    private function movePayrollData(DemoContext $context): void
    {
        $move = app(MovePayrollData::class);
        $user = $context->user;
        $company = $context->company;
        $employees = Employee::query()->orderBy('employee_number')->get();

        $move->start($user, $company, 2026);

        $rows = ['anstallningsnummer;fornamn;efternamn;personnummer;anstalld_fran;anstallningsform;loneform;lon;skattetabell;kolumn;epost;clearingnummer;kontonummer;sysselsattningsgrad;timmar_per_vecka;befattning'];

        foreach ($employees as $employee) {
            $employment = $this->employment($employee);
            $rows[] = implode(';', [
                $employee->employee_number,
                $employee->first_name,
                $employee->last_name,
                $employee->personal_number,
                $employment->start_date->toDateString(),
                $employment->employment_type,
                $employment->salary_form === 'HOURLY' ? 'TIMLON' : 'MANADSLON',
                self::kronor((int) ($employment->salary_form === 'HOURLY' ? $employment->hourly_rate_ore?->ore : $employment->monthly_salary_ore?->ore)),
                (string) $employee->tax_table,
                (string) $employee->tax_column,
                self::email($employee),
                '8327',
                sprintf('99%08d', 31_415_926 + 271_828 * $employee->employee_number),
                (string) intdiv($employment->employment_rate_bp, 100),
                (string) $employment->weekly_hours,
                (string) $employment->position_title,
            ]);
        }

        $move->employees($user, $company, implode("\n", $rows));

        $totals = ['anstallningsnummer;bruttolon;skatt;avgiftsunderlag;arbetsgivaravgifter'];
        $vacation = ['anstallningsnummer;semesterar_start;intjanade_dagar;uttagna_dagar;sparade_dagar'];

        foreach ($employees as $employee) {
            $totals[] = "{$employee->employee_number};0;0;0;0";

            if ($this->employment($employee)->salary_form === 'MONTHLY') {
                $vacation[] = implode(';', [$employee->employee_number, '2025-04-01', 25, 19 + self::pick("taken-{$employee->id}", 0, 3), self::pick("saved-{$employee->id}", 0, 2)]);
            }
        }

        $move->yearToDate($user, $company, implode("\n", $totals));
        $move->vacation($user, $company, implode("\n", $vacation));
        $move->complete($user, $company);
    }

    /** Occupational pension for the monthly paid staff, as a SAF-LO-like plan. */
    private function pensionPlan(DemoContext $context): void
    {
        $plan = app(SavePensionPlan::class)($context->user, $context->company, ['name' => 'Avtalspension (som SAF-LO)', 'template' => 'SAF_LO_LIKE', 'below_percent' => '4,5', 'above_percent' => '30']);

        foreach (Employee::query()->orderBy('employee_number')->get() as $employee) {
            if ($this->employment($employee)->salary_form === 'MONTHLY') {
                $this->saveEmployee($context, $employee, ['pension_plan_id' => $plan->id]);
            }
        }
    }

    /** The semesterår that ended on 31 March: one person saves the days she may, the rest are paid in April. */
    private function closeVacationYear(DemoContext $context): void
    {
        $close = app(CloseVacationYear::class);
        $yearStart = CarbonImmutable::parse('2025-04-01');
        $sara = $this->employeeOrFail($context, 'Sara');
        $preview = $close->preview($context->company, $yearStart);

        $close($context->user, $context->company, $yearStart, [$sara->id => min(3, $preview[$sara->id]['max_save'] ?? 0)]);
    }

    /**
     * One month's run: hours for the hourly staff (typed, or in September
     * from the scheduling system's file), staff meals, mileage, absence,
     * then calculated, approved by someone else, paid and booked.
     */
    private function payrollRun(DemoContext $context, string $period): void
    {
        $user = $context->user;
        $month = (int) substr($period, 5, 2);
        $run = $this->runs->create($user, $context->company, $period);

        if ($month === self::FILE_MONTH) {
            $this->payrollFile($context, $run);
        } else {
            $this->hours($context, $run);
        }

        $this->extras($context, $run, $month);

        $run = $this->runs->calculate($user, $run);
        $run = $this->runs->approve(self::approverUser() ?? $user, $run->fresh() ?? $run);
        app(SalaryPaymentFile::class)($user, $run);
        $run = $this->runs->markPaid($user, $run);
        $this->runs->post($user, $run);
    }

    private function hours(DemoContext $context, PayrollRun $run): void
    {
        $timlon = $this->payCode('TIMLON');

        foreach ($this->working($run, 'HOURLY') as $employee) {
            $this->runs->addInput($context->user, $run, [
                'employee_id' => $employee->id,
                'pay_code_id' => $timlon,
                'quantity' => (string) self::hoursFor($employee, $run->period),
                'note' => 'Timmar enligt schema',
            ]);
        }
    }

    /**
     * Hours and evening OB from the scheduling system's payroll file (CSV),
     * with its codes mapped once to the company's pay codes, and every line
     * approved into the run.
     */
    private function payrollFile(DemoContext $context, PayrollRun $run): void
    {
        $user = $context->user;
        $sources = app(PayrollImportSources::class);
        $source = $sources->create($user, $context->company, 'Schemasystemet (demo)');
        $sources->map($user, $context->company, $source, 'TIM', $this->payCode('TIMLON'));
        $sources->map($user, $context->company, $source, 'OBKV', $this->payCode('OBTILLAGG'));

        $lines = ['anstallningsnummer;personnummer;loneart;benamning;datum;datum_tom;antal;apris;belopp'];

        foreach ($this->working($run, 'HOURLY') as $employee) {
            $hours = self::hoursFor($employee, $run->period);
            $evening = intdiv($hours, 4);
            $lines[] = "{$employee->employee_number};;TIM;Arbetad tid;{$run->period}-01;{$run->periodEnd()->toDateString()};{$hours};;";
            $lines[] = "{$employee->employee_number};;OBKV;OB kväll;{$run->period}-01;{$run->periodEnd()->toDateString()};{$evening};26,79;";
        }

        $imports = app(PayrollFileImports::class);
        $import = $imports->upload($user, $run, $source, "loneunderlag-{$run->period}.csv", implode("\n", $lines)."\n");
        $imports->approve($user, $run, $import);
    }

    private function extras(DemoContext $context, PayrollRun $run, int $month): void
    {
        $user = $context->user;
        $sara = $this->employeeOrFail($context, 'Sara');
        $johan = $this->employeeOrFail($context, 'Johan');
        $input = fn (Employee $employee, string $code, string $quantity, string $note) => $this->runs->addInput($user, $run, ['employee_id' => $employee->id, 'pay_code_id' => $this->payCode($code), 'quantity' => $quantity, 'note' => $note]);

        // Staff meals in the restaurant are a taxable benefit at Skatteverket's value.
        foreach ([$sara, $johan] as $employee) {
            $input($employee, 'KOSTFORMAN_LUNCH', (string) self::pick("meals-{$employee->id}-{$run->period}", 12, 17), 'Personalmåltider');
        }

        if (in_array($month, [3, 6, 9], true)) {
            $input($johan, 'MILERSATTNING', (string) self::pick("mil-{$run->period}", 24, 46), 'Egen bil: grossistbesök och leverans av catering');
        }

        $absence = fn (string $name, string $type, string $from, string $to) => $this->runs->recordAbsence($user, $run, [
            'employee_id' => $this->employeeOrFail($context, $name)->id,
            'type' => $type,
            'date_from' => $from,
            'date_to' => $to,
            'hours' => '8',
        ]);

        match ($month) {
            2 => $absence('Ali', 'SICK', '2026-02-10', '2026-02-12'),
            5 => $absence('Lina', 'SICK', '2026-05-19', '2026-05-20'),
            7 => [$absence('Sara', 'VACATION', '2026-07-06', '2026-07-24'), $absence('Johan', 'VACATION', '2026-07-13', '2026-07-24'), $absence('Ali', 'VACATION', '2026-07-27', '2026-07-31')],
            8 => [$absence('Lina', 'VACATION', '2026-08-03', '2026-08-14'), $absence('Ali', 'VACATION', '2026-08-03', '2026-08-07')],
            default => null,
        };
    }

    private function postTax(DemoContext $context, string $period, CarbonImmutable $date): void
    {
        $run = PayrollRun::query()->where('period', $period)->where('type', 'REGULAR')->first();

        if ($run?->status === PayrollRun::POSTED) {
            $this->runs->postTax($context->user, $run, $date);
        }
    }

    /** The arbetsgivardeklaration for the payment month; filed months are marked submitted and accepted. */
    private function declare(DemoContext $context, string $period, bool $filed): void
    {
        $agi = app(AgiSubmissions::class);
        $submission = $agi->generate($context->user, $context->company, $period);

        if (! $filed) {
            return;
        }

        $submission = $agi->submitted($context->user, $submission, 'DEMO-'.str_replace('-', '', $period).'-'.str_pad((string) $submission->id, 4, '0', STR_PAD_LEFT));
        $agi->decided($context->user, $submission, true);
    }

    /**
     * This month's run, in progress: the hourly staff's hours approved from
     * the staff ledger after October's cut-off are included (or typed when
     * there is no ledger), a bonus and the agreement's OB and overtime are
     * added, and the run is calculated but not yet approved.
     */
    private function openRun(DemoContext $context, CarbonImmutable $month): void
    {
        $user = $context->user;
        $run = $this->runs->create($user, $context->company, $month->format('Y-m'));

        if (! TimeReport::query()->where('status', 'APPROVED')->whereNull('payroll_run_id')->exists()) {
            $this->hours($context, $run);
        } elseif (TimeReport::query()->where('status', 'APPROVED')->whereNull('payroll_run_id')->whereDate('date', '<', $month->toDateString())->exists()) {
            $this->runs->decideLateTimeReports($user, $run, true);
        }

        $this->runs->addInput($user, $run, ['employee_id' => $this->employeeOrFail($context, 'Johan')->id, 'pay_code_id' => $this->payCode('BONUS'), 'amount' => '2500', 'note' => 'Bonus för julbordssäsongens bokningar']);

        // With the restaurant's agreement (AgreementsExtras): OB and overtime priced by the agreement's rate on the work date.
        if (PayCode::query()->where('code', 'GR_OB')->exists()) {
            $saturday = $month->modify('first saturday of this month');
            $this->runs->addInput($user, $run, ['employee_id' => $this->employeeOrFail($context, 'Maja')->id, 'pay_code_id' => $this->payCode('GR_OB'), 'quantity' => '6', 'date_from' => $saturday->toDateString(), 'note' => 'Lördagskväll 16–22']);
            $this->runs->addInput($user, $run, ['employee_id' => $this->employeeOrFail($context, 'Elin')->id, 'pay_code_id' => $this->payCode('GR_OT_A'), 'quantity' => '2', 'date_from' => $saturday->toDateString(), 'note' => 'Stängde baren efter privat sällskap']);
        }

        $this->runs->calculate($user, $run->fresh() ?? $run);
    }

    /** @return list<Employee> employed during the run's month, with the given salary form */
    private function working(PayrollRun $run, string $form): array
    {
        $ids = Employment::query()
            ->where('salary_form', $form)
            ->whereDate('start_date', '<=', $run->periodEnd()->toDateString())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $run->periodStart()->toDateString()))
            ->pluck('employee_id');

        return array_values(Employee::query()->whereIn('id', $ids)->orderBy('employee_number')->get()->all());
    }

    /**
     * Updates an employee through SaveEmployee with what is stored, plus the
     * changes: the form keeps every field it is not asked to change.
     *
     * @param  array<string, mixed>  $changes
     */
    public function saveEmployee(DemoContext $context, Employee $employee, array $changes): Employee
    {
        $employment = $this->employment($employee);

        return app(SaveEmployee::class)($context->user, $context->company, [
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'email' => $employee->email,
            'tax_residence' => $employee->tax_residence,
            'tax_table' => $employee->tax_table,
            'tax_column' => $employee->tax_column,
            'start_date' => $employment->start_date->toDateString(),
            'employment_type' => $employment->employment_type,
            'salary_form' => $employment->salary_form,
            'salary' => self::kronor((int) ($employment->salary_form === 'HOURLY' ? $employment->hourly_rate_ore?->ore : $employment->monthly_salary_ore?->ore)),
            'employment_rate' => intdiv($employment->employment_rate_bp, 100),
            'weekly_hours' => $employment->weekly_hours,
            'position_title' => $employment->position_title,
            'vacation_rule' => $employment->vacation_rule,
            'vacation_days_per_year' => $employment->vacation_days_per_year,
            'vacation_payout_with_salary' => $employment->vacation_payout_with_salary,
            'pension_plan_id' => $employment->pension_plan_id,
            'user_id' => $employee->user_id,
            ...$changes,
        ], $employee);
    }

    public function employee(DemoContext $context, string $firstName): ?Employee
    {
        return Employee::query()->where('company_id', $context->company->id)->where('first_name', $firstName)->first();
    }

    public function employeeOrFail(DemoContext $context, string $firstName): Employee
    {
        return $this->employee($context, $firstName) ?? throw new \RuntimeException("No demo employee {$firstName}.");
    }

    private function employment(Employee $employee): Employment
    {
        return Employment::query()->where('employee_id', $employee->id)->latest('start_date')->firstOrFail();
    }

    private function payCode(string $code): int
    {
        return (int) PayCode::query()->where('code', $code)->value('id');
    }

    /** Synthetic address in the example.test domain, so nothing can ever be delivered. */
    public static function email(Employee $employee): string
    {
        $name = mb_strtolower("{$employee->first_name}.{$employee->last_name}");

        return strtr($name, ['å' => 'a', 'ä' => 'a', 'ö' => 'o', 'é' => 'e']).'@example.test';
    }

    /** Hours worked in a month by an hourly employee, in proportion to their usual extent. */
    private static function hoursFor(Employee $employee, string $period): int
    {
        return self::pick("hours-{$employee->id}-{$period}", 55, 95);
    }

    /** A number between $min and $max that is always the same for the same key: the demo stays reproducible. */
    public static function pick(string $key, int $min, int $max): int
    {
        return $min + (int) (crc32($key) % ($max - $min + 1));
    }

    private static function kronor(int $ore): string
    {
        return sprintf('%d.%02d', intdiv($ore, 100), $ore % 100);
    }
}
