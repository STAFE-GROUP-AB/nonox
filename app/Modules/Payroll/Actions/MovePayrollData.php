<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Actions\Accounting\AttachToVoucher;
use App\Domain\Accounting\VoucherStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\Engine\Steps\EmployerContributions;
use App\Modules\Payroll\Domain\PersonalNumber;
use App\Modules\Payroll\Models\Accumulator;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\ImportedPayslip;
use App\Modules\Payroll\Models\PayrollMigration;
use App\Modules\Payroll\Models\VacationOpeningBalance;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Migration\PayrollCsv;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Moves payroll data from an earlier payroll system (PAYROLL_MODULE.md
 * B.16), in the manual mode: CSV files in our own format and the old
 * payslips as PDF. While the move is in progress no run can be calculated.
 *
 * Each file is checked as a whole and imported only when every row is
 * right, so a file can be corrected and read again. The move is complete
 * when the year-to-date tax and employer contributions agree with the
 * bookkeeping (2710 credited, 7510 debited) for the year.
 *
 * TODO(legal-verify): the earlier system's own export formats (for example
 * PAXml) and API, to read the data without the CSV step.
 */
class MovePayrollData
{
    public const int TAX_ACCOUNT = 2710;

    public const int CONTRIBUTIONS_ACCOUNT = 7510;

    /** The accumulator keys the engine reads, by CSV column. */
    private const array ACCUMULATORS = [
        'bruttolon' => 'gross_ytd',
        'skatt' => 'tax_ytd',
        'avgiftsunderlag' => 'contribution_basis_ytd',
        'arbetsgivaravgifter' => 'contributions_ytd',
        'formaner' => 'benefit_ytd',
        'engangsbelopp' => 'one_off_ytd',
    ];

    private const int ORE_PER_KRONA = 100;

    public function __construct(private readonly ModuleManager $modules) {}

    public function start(User $user, Company $company, int $year): PayrollMigration
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, function () use ($user, $company, $year): PayrollMigration {
            $migration = PayrollMigration::query()->first();

            if ($migration instanceof PayrollMigration) {
                return $migration;
            }

            $migration = PayrollMigration::create(['year' => $year, 'report' => ['employees' => []], 'started_by' => $user->id]);
            Audit::record('payroll.migration_started', $migration, ['year' => $year], $company, $user);

            return $migration;
        });
    }

    /** @return int employees created or updated */
    public function employees(User $user, Company $company, string $contents): int
    {
        $migration = $this->open($user, $company);
        $rows = PayrollCsv::read($contents, PayrollCsv::EMPLOYEES, PayrollCsv::EMPLOYEES_OPTIONAL);

        return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($user, $company, $migration, $rows): int {
            $existing = Employee::query()->get()->keyBy(fn (Employee $employee): string => (string) PersonalNumber::tryFrom((string) $employee->personal_number)?->digits);
            $map = (array) ($migration->report['employees'] ?? []);
            $errors = [];

            foreach ($rows as $row) {
                $digits = PersonalNumber::tryFrom($row['personnummer'])?->digits;
                $optional = fn (string $column): ?string => ($row[$column] ?? '') === '' ? null : $row[$column];

                try {
                    $employee = app(SaveEmployee::class)($user, $company, [
                        'first_name' => $row['fornamn'],
                        'last_name' => $row['efternamn'],
                        'personal_number' => $row['personnummer'],
                        'email' => $optional('epost'),
                        'bank_clearing' => $optional('clearingnummer'),
                        'bank_account' => $optional('kontonummer'),
                        'tax_residence' => 'SE',
                        'tax_table' => $row['skattetabell'],
                        'tax_column' => $row['kolumn'],
                        'start_date' => $row['anstalld_fran'],
                        'employment_type' => mb_strtoupper($row['anstallningsform']),
                        'salary_form' => mb_strtoupper($row['loneform']) === 'TIMLON' || mb_strtoupper($row['loneform']) === 'HOURLY' ? 'HOURLY' : 'MONTHLY',
                        'salary' => $row['lon'],
                        'employment_rate' => (int) ($optional('sysselsattningsgrad') ?? 100),
                        'weekly_hours' => $optional('timmar_per_vecka'),
                        'position_title' => $optional('befattning'),
                    ], $digits === null ? null : $existing->get($digits));
                } catch (ValidationException $exception) {
                    $errors[] = __('Rad :line: :error', ['line' => $row['_line'], 'error' => (string) collect($exception->errors())->flatten()->first()]);

                    continue;
                }

                $map[$row['anstallningsnummer']] = $employee->id;
            }

            if ($errors !== []) {
                throw ValidationException::withMessages(['file' => $errors]);
            }

            $migration->forceFill(['report' => [...$migration->report, 'employees' => $map]])->save();
            Audit::record('payroll.migration_employees', $migration, ['employees' => count($rows)], $company, $user);

            return count($rows);
        }));
    }

    /** Year-to-date totals per employee for the migration year, as accumulators. */
    public function yearToDate(User $user, Company $company, string $contents): int
    {
        $migration = $this->open($user, $company);
        $rows = PayrollCsv::read($contents, PayrollCsv::YEAR_TO_DATE, PayrollCsv::YEAR_TO_DATE_OPTIONAL);

        return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($user, $company, $migration, $rows): int {
            $errors = [];
            $totals = [];

            foreach ($rows as $row) {
                $employeeId = $this->employeeId($migration, $row['anstallningsnummer']);

                if ($employeeId === null) {
                    $errors[] = __('Rad :line: anställningsnummer :number finns inte i filen med anställda.', ['line' => $row['_line'], 'number' => $row['anstallningsnummer']]);

                    continue;
                }

                foreach (self::ACCUMULATORS as $column => $key) {
                    $value = ($row[$column] ?? '') === '' ? 0 : PayrollCsv::ore($row[$column]);

                    if ($value === null) {
                        $errors[] = __('Rad :line: :column går inte att läsa som belopp.', ['line' => $row['_line'], 'column' => $column]);

                        continue 2;
                    }

                    $totals[$employeeId][$key] = $value;
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages(['file' => $errors]);
            }

            foreach ($totals as $employeeId => $values) {
                foreach ($values as $key => $value) {
                    Accumulator::query()->updateOrCreate(['employee_id' => $employeeId, 'scope' => 'CALENDAR_YEAR', 'year' => $migration->year, 'key' => $key], ['value' => $value]);
                }

                // Pay that carried no contributions in the earlier system was avgiftsfri; it joins the basis once the year passes the threshold (SAL 2 kap. 24 § fjärde stycket, #71).
                $free = ($values['contributions_ytd'] ?? 0) === 0 ? max(0, $values['contribution_basis_ytd'] ?? 0) : 0;
                Accumulator::query()->updateOrCreate(['employee_id' => $employeeId, 'scope' => 'CALENDAR_YEAR', 'year' => $migration->year, 'key' => EmployerContributions::FREE_BASIS_YTD], ['value' => $free]);
            }

            $migration->forceFill(['report' => [...$migration->report, 'year_to_date' => $totals]])->save();
            Audit::record('payroll.migration_year_to_date', $migration, ['employees' => count($totals)], $company, $user);

            return count($totals);
        }));
    }

    /** Vacation balances per employee for the semesterår now running. */
    public function vacation(User $user, Company $company, string $contents): int
    {
        $migration = $this->open($user, $company);
        $rows = PayrollCsv::read($contents, PayrollCsv::VACATION, PayrollCsv::VACATION_OPTIONAL);
        $startMonth = (int) ($this->modules->settings(PayrollModule::KEY, $company)['vacation_year_start_month'] ?? 4);

        return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($user, $company, $migration, $rows, $startMonth): int {
            $errors = [];
            $balances = [];

            foreach ($rows as $row) {
                $employeeId = $this->employeeId($migration, $row['anstallningsnummer']);
                $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['semesterar_start']) === 1 ? CarbonImmutable::parse($row['semesterar_start']) : null;
                $days = array_map(fn (string $column): ?int => preg_match('/^\d{1,3}$/', $row[$column]) === 1 ? (int) $row[$column] : null, ['intjanade_dagar', 'uttagna_dagar', 'sparade_dagar']);
                $basis = ($row['semesterlonegrundande_lon'] ?? '') === '' ? null : PayrollCsv::ore((string) $row['semesterlonegrundande_lon']);

                $error = match (true) {
                    $employeeId === null => self::text('anställningsnummer :number finns inte i filen med anställda.', ['number' => $row['anstallningsnummer']]),
                    $start === null || $start->day !== 1 || $start->month !== $startMonth => self::text('semesteråret börjar den första i månad :month enligt löneinställningarna.', ['month' => $startMonth]),
                    in_array(null, $days, true) => self::text('dagarna ska anges som hela tal.'),
                    ($row['semesterlonegrundande_lon'] ?? '') !== '' && $basis === null => self::text('den semesterlönegrundande lönen går inte att läsa som belopp.'),
                    default => null,
                };

                if ($error !== null) {
                    $errors[] = __('Rad :line: :error', ['line' => $row['_line'], 'error' => $error]);

                    continue;
                }

                $balances[] = ['employee_id' => (int) $employeeId, 'year_start' => $start->toDateString(), 'days_earned' => (int) $days[0], 'days_taken' => (int) $days[1], 'days_saved' => (int) $days[2], 'basis_ore' => $basis];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages(['file' => $errors]);
            }

            foreach ($balances as $balance) {
                VacationOpeningBalance::query()->updateOrCreate(['employee_id' => $balance['employee_id'], 'year_start' => $balance['year_start']], $balance);
            }

            Audit::record('payroll.migration_vacation', $migration, ['employees' => count($balances)], $company, $user);

            return count($balances);
        }));
    }

    /**
     * Payslips from the earlier system as PDF, each named
     * anställningsnummer_ÅÅÅÅ-MM.pdf. The same file is kept once.
     *
     * @param  list<UploadedFile>  $files
     */
    public function payslips(User $user, Company $company, array $files): int
    {
        $migration = $this->open($user, $company);

        return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($user, $company, $migration, $files): int {
            $errors = [];
            $stored = 0;

            foreach ($files as $file) {
                $name = $file->getClientOriginalName();
                $matched = preg_match('/^(\w+)_(\d{4}-(0[1-9]|1[0-2]))\.pdf$/i', $name, $parts) === 1;
                $employeeId = $matched ? $this->employeeId($migration, $parts[1]) : null;

                if (! $matched || $employeeId === null || $file->getMimeType() !== 'application/pdf') {
                    $errors[] = __(':file: döp filen till anställningsnummer_ÅÅÅÅ-MM.pdf, med ett anställningsnummer från filen med anställda.', ['file' => $name]);

                    continue;
                }

                $contents = (string) file_get_contents($file->getRealPath());
                $sha = hash('sha256', $contents);

                if (ImportedPayslip::query()->where('employee_id', $employeeId)->where('sha256', $sha)->exists()) {
                    continue;
                }

                $path = "imported-payslips/{$company->id}/{$sha}.pdf";
                Storage::disk(AttachToVoucher::DISK)->put($path, $contents);
                ImportedPayslip::create(['employee_id' => $employeeId, 'period' => $parts[2], 'file_path' => $path, 'original_name' => basename($name), 'sha256' => $sha, 'uploaded_by' => $user->id]);
                $stored++;
            }

            if ($errors !== []) {
                throw ValidationException::withMessages(['payslips' => $errors]);
            }

            Audit::record('payroll.migration_payslips', $migration, ['files' => $stored], $company, $user);

            return $stored;
        }));
    }

    /**
     * The imported year-to-date totals beside the bookkeeping for the year.
     *
     * @return array{employees: list<array{name: string, gross_ore: int, tax_ore: int, contributions_ore: int}>, tax_ore: int, contributions_ore: int, booked_tax_ore: int, booked_contributions_ore: int, agrees: bool}
     */
    public function reconciliation(Company $company): array
    {
        return CurrentCompany::run($company, function (): array {
            $migration = PayrollMigration::query()->firstOrFail();
            $totals = (array) ($migration->report['year_to_date'] ?? []);
            $employees = Employee::query()->whereIn('id', array_keys($totals))->orderBy('employee_number')->get();
            $rows = array_values($employees->map(fn (Employee $employee): array => [
                'name' => "{$employee->first_name} {$employee->last_name}",
                'gross_ore' => (int) ($totals[$employee->id]['gross_ytd'] ?? 0),
                'tax_ore' => (int) ($totals[$employee->id]['tax_ytd'] ?? 0),
                'contributions_ore' => (int) ($totals[$employee->id]['contributions_ytd'] ?? 0),
            ])->all());
            $accounts = Account::query()->whereIn('number', [self::TAX_ACCOUNT, self::CONTRIBUTIONS_ACCOUNT])->pluck('id', 'number');
            $vouchers = Voucher::query()->where('status', VoucherStatus::Posted)->whereBetween('voucher_date', ["{$migration->year}-01-01", "{$migration->year}-12-31"])->select('id');
            $sum = fn (int $account, string $column): int => (int) VoucherRow::query()->whereIn('voucher_id', $vouchers)->where('account_id', $accounts->get($account))->sum($column);
            $tax = array_sum(array_column($rows, 'tax_ore'));
            $contributions = array_sum(array_column($rows, 'contributions_ore'));
            // Tax withheld is credited to 2710 by the salary vouchers; payments to the tax account are debits and left out.
            $bookedTax = $sum(self::TAX_ACCOUNT, 'credit_ore');
            $bookedContributions = $sum(self::CONTRIBUTIONS_ACCOUNT, 'debit_ore') - $sum(self::CONTRIBUTIONS_ACCOUNT, 'credit_ore');
            // Each employee's amounts may be rounded to whole kronor in the earlier system.
            $tolerance = max(1, count($rows)) * self::ORE_PER_KRONA;

            return [
                'employees' => $rows,
                'tax_ore' => $tax,
                'contributions_ore' => $contributions,
                'booked_tax_ore' => $bookedTax,
                'booked_contributions_ore' => $bookedContributions,
                'agrees' => $rows !== [] && abs($tax - $bookedTax) <= $tolerance && abs($contributions - $bookedContributions) <= $tolerance,
            ];
        });
    }

    public function complete(User $user, Company $company): PayrollMigration
    {
        $migration = $this->open($user, $company);
        $reconciliation = $this->reconciliation($company);

        if (($migration->report['employees'] ?? []) === [] || ! isset($migration->report['year_to_date'])) {
            throw ValidationException::withMessages(['complete' => [__('Läs in de anställda och beloppen hittills i år först.')]]);
        }

        if (! $reconciliation['agrees']) {
            throw ValidationException::withMessages(['complete' => [__('Skatt och arbetsgivaravgifter hittills i år stämmer inte med bokföringen för :year. Läs in bokföringen för året eller rätta filen med beloppen.', ['year' => $migration->year])]]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $migration, $reconciliation): PayrollMigration {
            $migration->forceFill([
                'status' => PayrollMigration::COMPLETE,
                'report' => [...$migration->report, 'reconciliation' => $reconciliation],
                'completed_by' => $user->id,
                'completed_at' => CarbonImmutable::now(),
            ])->save();
            Audit::record('payroll.migration_completed', $migration, ['tax_ore' => $reconciliation['tax_ore'], 'contributions_ore' => $reconciliation['contributions_ore']], $company, $user);

            return $migration;
        });
    }

    /** @param array<string, int|string> $replace */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }

    private function employeeId(PayrollMigration $migration, string $number): ?int
    {
        $id = $migration->report['employees'][$number] ?? null;

        return $id === null ? null : (int) $id;
    }

    private function open(User $user, Company $company): PayrollMigration
    {
        $this->authorize($user, $company);

        $migration = CurrentCompany::run($company, fn (): ?PayrollMigration => PayrollMigration::query()->first());

        if (! $migration instanceof PayrollMigration || $migration->status !== PayrollMigration::IN_PROGRESS) {
            throw ValidationException::withMessages(['file' => [__('Starta flytten av lönedata först.')]]);
        }

        return $migration;
    }

    private function authorize(User $user, Company $company): void
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'payroll:manage')) {
            throw new AuthorizationException;
        }
    }
}
