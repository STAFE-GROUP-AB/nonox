<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\PersonalNumber;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollFileImport;
use App\Modules\Payroll\Models\PayrollFileImportLine;
use App\Modules\Payroll\Models\PayrollImportMapping;
use App\Modules\Payroll\Models\PayrollImportSource;
use App\Modules\Payroll\Models\PayrollInput;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Paxml\PaxmlCodes;
use App\Modules\Payroll\Support\Paxml\PaxmlReader;
use App\Modules\Payroll\Support\Paxml\PayrollFile;
use App\Modules\Payroll\Support\Paxml\PayrollFileCsv;
use App\Modules\Payroll\Support\Paxml\PayrollFileLine;
use App\Modules\Payroll\Support\Paxml\UnreadablePayrollFile;
use App\Modules\Payroll\Support\Run\TimeReportPay;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Payroll files (löneunderlag) from scheduling and time systems, PAXml or
 * CSV, read into an open payroll run.
 *
 * 1. upload(): the file is read, its lines matched to employees (by
 *    anställningsnummer, else personal number) and to the company's pay
 *    codes through the source system's mapping, and stored as proposals.
 *    The original is kept encrypted on the private disk. The same file
 *    (SHA-256) is read once per period.
 * 2. A person approves lines (approve()); each becomes an input to the run
 *    with source IMPORT, and the run must be calculated again. Nothing is
 *    approved, calculated or posted by the import itself.
 * 3. reprocess() reads the stored file again, after a mapping or an
 *    employee was added: approved lines stay, the others are replaced.
 *
 * Absence and balances in a PAXml file are never turned into pay (see
 * PaxmlCodes). Every step is audited, without personal numbers.
 */
class PayrollFileImports
{
    public const string DISK = AttachToVoucher::DISK;

    public function __construct(
        private readonly ModuleManager $modules,
        private readonly PayrollRuns $runs,
        private readonly PaxmlReader $paxml,
        private readonly PayrollFileCsv $csv,
    ) {}

    public function upload(User $user, PayrollRun $run, PayrollImportSource $source, string $originalName, string $contents): PayrollFileImport
    {
        $company = $this->open($user, $run);

        if ($contents === '') {
            throw ValidationException::withMessages(['file' => __('Filen är tom.')]);
        }

        $file = $this->parse($contents);
        $sha = hash('sha256', $contents);

        return CurrentCompany::run($company, function () use ($user, $company, $run, $source, $originalName, $contents, $file, $sha): PayrollFileImport {
            $source = PayrollImportSource::query()->findOrFail($source->id);
            $earlier = PayrollFileImport::query()->where('period', $run->period)->where('sha256', $sha)->first();

            if ($earlier !== null) {
                throw ValidationException::withMessages(['file' => __('Den här filen är redan inläst för :period, :date. Samma fil läses bara in en gång per period. Läs in den igen från den inlästa filen om något har ändrats.', [
                    'period' => $run->periodStart()->translatedFormat('F Y'),
                    'date' => $earlier->created_at?->format('Y-m-d H:i'),
                ])]);
            }

            $path = "payroll-imports/{$company->id}/{$sha}.".($file->format === PayrollFileImport::PAXML ? 'xml' : 'csv');

            // Encrypted at rest by the private disk; it holds personal numbers.
            Storage::disk(self::DISK)->put($path, $contents);

            try {
                $import = DB::transaction(function () use ($user, $run, $source, $originalName, $contents, $file, $sha, $path): PayrollFileImport {
                    $import = PayrollFileImport::create([
                        'payroll_run_id' => $run->id,
                        'payroll_import_source_id' => $source->id,
                        'period' => $run->period,
                        'format' => $file->format,
                        'format_version' => $file->version === null ? null : mb_substr($file->version, 0, 10),
                        'original_name' => mb_substr(basename($originalName), 0, 255),
                        'file_path' => $path,
                        'sha256' => $sha,
                        'size' => strlen($contents),
                        'report' => self::report($file),
                        'uploaded_by' => $user->id,
                    ]);

                    $this->buildLines($import, $run, $file, []);

                    return $import;
                });
            } catch (Throwable $exception) {
                if (! PayrollFileImport::query()->where('file_path', $path)->exists()) {
                    Storage::disk(self::DISK)->delete($path);
                }

                throw $exception instanceof UniqueConstraintViolationException
                    ? ValidationException::withMessages(['file' => __('Den här filen är redan inläst för perioden.')])
                    : $exception;
            }

            Audit::record('payroll.file_imported', $import, [
                'run_id' => $run->id,
                'source' => $source->name,
                'format' => $import->format,
                'version' => $import->format_version,
                'sha256' => $sha,
                'lines' => count($file->lines),
            ], $company, $user);

            return $import;
        });
    }

    /**
     * Reads the stored file again with today's mappings and employees. Lines
     * already approved are kept as they are; every other line of the file is
     * replaced.
     */
    public function reprocess(User $user, PayrollRun $run, PayrollFileImport $import): PayrollFileImport
    {
        $company = $this->open($user, $run);

        return CurrentCompany::run($company, function () use ($user, $company, $run, $import): PayrollFileImport {
            $import = $this->import($run, $import);
            $file = $this->parse((string) Storage::disk(self::DISK)->get($import->file_path));

            DB::transaction(function () use ($run, $import, $file): void {
                $approved = PayrollFileImportLine::query()
                    ->where('payroll_file_import_id', $import->id)
                    ->where('status', PayrollFileImportLine::APPROVED)
                    ->lockForUpdate()
                    ->pluck('line_no')
                    ->map(fn (mixed $lineNo): int => (int) $lineNo)
                    ->values()
                    ->all();

                PayrollFileImportLine::query()
                    ->where('payroll_file_import_id', $import->id)
                    ->where('status', '<>', PayrollFileImportLine::APPROVED)
                    ->delete();

                $this->buildLines($import, $run, $file, $approved);
                $import->forceFill(['report' => self::report($file)])->save();
            });

            Audit::record('payroll.file_reprocessed', $import, ['run_id' => $run->id], $company, $user);

            return $import;
        });
    }

    /**
     * Maps an external code of the file's source system, for this and every
     * later file from it, and reads the file again.
     */
    public function map(User $user, PayrollRun $run, PayrollFileImport $import, string $externalCode, ?int $payCodeId, bool $ignored = false): PayrollFileImport
    {
        $company = $this->open($user, $run);
        $source = CurrentCompany::run($company, fn (): PayrollImportSource => PayrollImportSource::query()->findOrFail($this->import($run, $import)->payroll_import_source_id));

        app(PayrollImportSources::class)->map($user, $company, $source, $externalCode, $payCodeId, $ignored);

        return $this->reprocess($user, $run, $import);
    }

    /**
     * A person approves proposed lines: each becomes an input to the run
     * (source IMPORT). Only lines without problems can be approved. With no
     * ids, every line of the file that can be approved is.
     *
     * @param  ?list<int>  $lineIds
     * @return int how many lines were approved
     */
    public function approve(User $user, PayrollRun $run, PayrollFileImport $import, ?array $lineIds = null): int
    {
        $company = $this->open($user, $run);

        return CurrentCompany::run($company, fn (): int => DB::transaction(fn (): int => $this->approveLines($user, $company, $run, $import, $lineIds)));
    }

    /** @param  ?list<int>  $lineIds */
    private function approveLines(User $user, Company $company, PayrollRun $run, PayrollFileImport $import, ?array $lineIds): int
    {
        $import = $this->import($run, $import);
        $lines = PayrollFileImportLine::query()
            ->where('payroll_file_import_id', $import->id)
            ->where('status', PayrollFileImportLine::PROPOSED)
            ->when($lineIds !== null, fn ($query) => $query->whereIn('id', $lineIds ?? []))
            ->orderBy('line_no')
            ->lockForUpdate()
            ->get()
            ->filter(fn (PayrollFileImportLine $line): bool => $line->canBeApproved())
            ->values();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('Det finns inga rader som kan godkännas. Rader med anmärkningar måste rättas först.')]);
        }

        $now = now();

        foreach ($lines as $line) {
            $input = PayrollInput::create([
                'payroll_run_id' => $run->id,
                'employee_id' => $line->employee_id,
                'pay_code_id' => $line->pay_code_id,
                'date_from' => $line->date_from,
                'date_to' => $line->date_to,
                'quantity' => $line->quantity,
                'rate_ore' => $line->rate_ore?->ore,
                'amount_ore' => $line->amount_ore?->ore,
                'note' => $line->description === null ? null : mb_substr($line->description, 0, 255),
                'source' => 'IMPORT',
            ]);

            $line->forceFill([
                'status' => PayrollFileImportLine::APPROVED,
                'payroll_input_id' => $input->id,
                'decided_by' => $user->id,
                'decided_at' => $now,
            ])->save();
        }

        $this->runs->backToDraft(self::locked($run));

        Audit::record('payroll.file_lines_approved', $import, [
            'run_id' => $run->id,
            'line_ids' => $lines->pluck('id')->all(),
            'count' => $lines->count(),
        ], $company, $user);

        return $lines->count();
    }

    public function reject(User $user, PayrollRun $run, PayrollFileImport $import, int $lineId): void
    {
        $company = $this->open($user, $run);

        CurrentCompany::run($company, function () use ($user, $company, $run, $import, $lineId): void {
            $import = $this->import($run, $import);
            $line = PayrollFileImportLine::query()->where('payroll_file_import_id', $import->id)->where('status', PayrollFileImportLine::PROPOSED)->findOrFail($lineId);
            $line->forceFill(['status' => PayrollFileImportLine::REJECTED, 'decided_by' => $user->id, 'decided_at' => now()])->save();

            Audit::record('payroll.file_line_rejected', $import, ['run_id' => $run->id, 'line_id' => $line->id], $company, $user);
        });
    }

    /** Takes back an approved line: its input leaves the run and the line is proposed again. */
    public function unapprove(User $user, PayrollRun $run, PayrollFileImport $import, int $lineId): void
    {
        $company = $this->open($user, $run);

        CurrentCompany::run($company, function () use ($user, $company, $run, $import, $lineId): void {
            DB::transaction(function () use ($run, $import, $lineId): void {
                $import = $this->import($run, $import);
                $line = PayrollFileImportLine::query()->where('payroll_file_import_id', $import->id)->where('status', PayrollFileImportLine::APPROVED)->lockForUpdate()->findOrFail($lineId);
                $inputId = $line->payroll_input_id;
                self::release($line);

                if ($inputId !== null) {
                    PayrollInput::query()->whereKey($inputId)->delete();
                }

                $this->runs->backToDraft(self::locked($run));
            });

            Audit::record('payroll.file_line_unapproved', $import, ['run_id' => $run->id, 'line_id' => $lineId], $company, $user);
        });
    }

    /**
     * Removes a file from an open run: its approved inputs leave the run,
     * its lines are deleted, and the stored original unless another import
     * of the same file still needs it.
     */
    public function delete(User $user, PayrollRun $run, PayrollFileImport $import): void
    {
        $company = $this->open($user, $run);

        CurrentCompany::run($company, function () use ($user, $company, $run, $import): void {
            $import = $this->import($run, $import);
            $path = $import->file_path;

            $removed = DB::transaction(function () use ($run, $import): int {
                $inputIds = PayrollFileImportLine::query()->where('payroll_file_import_id', $import->id)->whereNotNull('payroll_input_id')->pluck('payroll_input_id')->all();

                PayrollFileImportLine::query()->where('payroll_file_import_id', $import->id)->delete();
                PayrollInput::query()->whereIn('id', $inputIds)->delete();
                $import->delete();

                if ($inputIds !== []) {
                    $this->runs->backToDraft(self::locked($run));
                }

                return count($inputIds);
            });

            if (! PayrollFileImport::query()->where('file_path', $path)->exists()) {
                Storage::disk(self::DISK)->delete($path);
            }

            Audit::record('payroll.file_import_deleted', $import, ['run_id' => $run->id, 'sha256' => $import->sha256, 'inputs_removed' => $removed], $company, $user);
        });
    }

    /** Called when an approved line's input is removed in the run: the line is proposed again. */
    public static function release(PayrollFileImportLine $line): void
    {
        $line->forceFill(['status' => PayrollFileImportLine::PROPOSED, 'payroll_input_id' => null, 'decided_by' => null, 'decided_at' => null])->save();
    }

    private function parse(string $contents): PayrollFile
    {
        try {
            return self::isXml($contents) ? $this->paxml->read($contents) : $this->csv->read($contents);
        } catch (UnreadablePayrollFile $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }
    }

    private static function isXml(string $contents): bool
    {
        // Bytes, not UTF-8: a PAXml file is often ISO-8859-1. A UTF-8 byte order mark is skipped.
        $start = str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3, 1024) : substr($contents, 0, 1024);

        return str_starts_with(ltrim($start), '<');
    }

    /** @return array<string, mixed> */
    private static function report(PayrollFile $file): array
    {
        return [
            'version_known' => $file->format !== PayrollFileImport::PAXML || in_array($file->version, PaxmlReader::VERSIONS, true),
            'program' => $file->program === null ? null : mb_substr($file->program, 0, 80),
            'ignored_sections' => $file->ignoredSections,
            'lines' => count($file->lines),
        ];
    }

    /**
     * Matches every line of the file and stores it, except those whose line
     * number is in $keep (approved earlier). Employees, employments, pay
     * codes and mappings are read once for the whole file.
     *
     * @param  array<int, int>  $keep
     */
    private function buildLines(PayrollFileImport $import, PayrollRun $run, PayrollFile $file, array $keep): void
    {
        $match = $this->employeeMatcher($file);
        $employed = Employment::query()
            ->whereDate('start_date', '<=', $run->periodEnd()->toDateString())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $run->periodStart()->toDateString()))
            ->pluck('employee_id')
            ->flip();
        $mappings = $this->mappings($import->payroll_import_source_id, $file);
        $payCodes = PayrollImportSources::payCodes()->keyBy('id');
        $periodStart = $run->periodStart()->toDateString();
        $periodEnd = $run->periodEnd()->toDateString();
        $now = now();
        $rows = [];

        foreach ($file->lines as $line) {
            if (in_array($line->lineNo, $keep, true)) {
                continue;
            }

            $row = $this->line($line, $match, $mappings, $payCodes);

            if ($row['employee_id'] !== null && ! $employed->has($row['employee_id'])) {
                $row['warnings'][] = 'not_employed';
            }

            if (($row['date_from'] !== null && $row['date_from'] < $periodStart) || ($row['date_to'] !== null && $row['date_to'] > $periodEnd)) {
                $row['warnings'][] = 'outside_period';
            }

            $rows[] = [
                ...$row,
                'company_id' => $import->company_id,
                'payroll_file_import_id' => $import->id,
                'line_no' => $line->lineNo,
                'section' => $line->section,
                'description' => $line->description === null ? null : Crypt::encryptString($line->description),
                'problems' => json_encode($row['problems'], JSON_THROW_ON_ERROR),
                'warnings' => json_encode($row['warnings'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            PayrollFileImportLine::query()->insert($chunk);
        }
    }

    /**
     * @param  callable(PayrollFileLine): array{employee_id: ?int, problem: ?string}  $match
     * @param  Collection<string, PayrollImportMapping>  $mappings
     * @param  Collection<int, PayCode>  $payCodes
     * @return array{employee_ref: ?string, person_ref: ?string, employee_id: ?int, external_code: ?string, date_from: ?string, date_to: ?string, quantity: ?string, rate_ore: ?int, amount_ore: ?int, pay_code_id: ?int, status: string, problems: list<string>, warnings: list<string>}
     */
    private function line(PayrollFileLine $line, callable $match, Collection $mappings, Collection $payCodes): array
    {
        $problems = [];
        $warnings = [];
        $skip = null;
        $code = $line->code === null ? null : mb_strtoupper(trim($line->code));
        $employee = $match($line);

        if ($employee['problem'] !== null) {
            $problems[] = $employee['problem'];
        }

        $quantity = self::quantity($line->quantity);
        $rate = self::ore($line->rate);
        $amount = self::ore($line->amount);

        if ($quantity === false || $rate === false || $amount === false) {
            $problems[] = 'bad_number';
        }

        $quantity = $quantity === false ? null : $quantity;
        $rate = $rate === false ? null : $rate;
        $amount = $amount === false ? null : $amount;

        if ($line->dateFrom === '' || $line->dateTo === '' || ! self::validDate($line->dateFrom) || ! self::validDate($line->dateTo)) {
            $problems[] = 'bad_date';
        }

        $mapping = $code === null ? null : $mappings->get($code);
        $payCode = $mapping?->pay_code_id === null ? null : $payCodes->get($mapping->pay_code_id);

        if ($code === null || mb_strlen($code) > 40) {
            $problems[] = 'missing_code';
        } elseif (PaxmlCodes::isAbsence($line)) {
            $skip = 'absence_code';
        } elseif (PaxmlCodes::isBalance($line)) {
            $skip = 'balance_code';
        } elseif ($mapping?->ignored === true) {
            $skip = 'ignored_code';
        } elseif ($payCode === null) {
            $problems[] = 'unmapped_code';
        }

        if ($quantity === null && $amount === null && ! in_array('bad_number', $problems, true)) {
            $problems[] = 'missing_value';
        } elseif (self::negative($quantity) || ($rate ?? 0) < 0 || ($amount ?? 0) < 0) {
            $problems[] = 'negative';
        } elseif (($amount === null || $amount === 0) && ($quantity === null || BigDecimal::of($quantity)->isZero())) {
            $skip ??= 'zero';
        } elseif ($payCode !== null && $amount === null && $rate === null && $payCode->code !== TimeReportPay::PAY_CODE) {
            // Only hourly pay may take the employment's hourly rate; OB or overtime at that rate would be wrong.
            $problems[] = 'rate_required';
        }

        if ($amount !== null && $quantity !== null && $rate !== null
            && BigDecimal::of($quantity)->multipliedBy($rate)->toScale(0, RoundingMode::HalfUp)->toInt() !== $amount) {
            $warnings[] = 'amount_differs';
        }

        return [
            'employee_ref' => $line->employeeRef === null ? null : mb_substr($line->employeeRef, 0, 40),
            'person_ref' => $employee['employee_id'] === null && $line->personalNumber !== null ? self::maskedPersonalNumber($line->personalNumber) : null,
            'employee_id' => $employee['employee_id'],
            'external_code' => $code === null ? null : mb_substr($code, 0, 40),
            'date_from' => self::validDate($line->dateFrom) && $line->dateFrom !== '' ? $line->dateFrom : null,
            'date_to' => self::validDate($line->dateTo) && $line->dateTo !== '' ? $line->dateTo : null,
            'quantity' => $quantity,
            'rate_ore' => $rate,
            'amount_ore' => $amount,
            'pay_code_id' => $skip === null ? $payCode?->id : null,
            // A line that is never paid is skipped whatever else is wrong with it.
            'status' => $skip !== null ? PayrollFileImportLine::SKIPPED : PayrollFileImportLine::PROPOSED,
            'problems' => $skip !== null ? [$skip] : array_values(array_unique($problems)),
            'warnings' => $warnings,
        ];
    }

    /**
     * Matches a line to an employee: by anställningsnummer, else by personal
     * number. When the file gives both and they point at different people,
     * neither is trusted. Personal numbers are decrypted in memory, once per
     * file, and only when the file has any.
     *
     * @return callable(PayrollFileLine): array{employee_id: ?int, problem: ?string}
     */
    private function employeeMatcher(PayrollFile $file): callable
    {
        $usesPersonalNumbers = collect($file->lines)->contains(fn (PayrollFileLine $line): bool => $line->personalNumber !== null);
        $employees = Employee::query()->get($usesPersonalNumbers ? ['id', 'employee_number', 'personal_number'] : ['id', 'employee_number']);
        $byNumber = $employees->mapWithKeys(fn (Employee $employee): array => [$employee->employee_number => $employee->id]);
        $byPersonalNumber = $usesPersonalNumbers
            ? $employees->mapWithKeys(fn (Employee $employee): array => [(string) preg_replace('/\D/', '', (string) $employee->personal_number) => $employee->id])
            : collect();

        return function (PayrollFileLine $line) use ($byNumber, $byPersonalNumber): array {
            $reference = $line->employeeRef === null ? null : ltrim(trim($line->employeeRef), '0');
            $byReference = $reference !== null && ctype_digit($reference) ? $byNumber->get((int) $reference) : null;
            $digits = $line->personalNumber === null ? null : (string) preg_replace('/\D/', '', $line->personalNumber);
            $byPerson = $digits === null || $digits === '' ? null : $byPersonalNumber->get($digits);

            if ($byReference !== null && $digits !== null && $digits !== '' && $byPerson !== $byReference) {
                return ['employee_id' => null, 'problem' => 'employee_conflict'];
            }

            $id = $byReference ?? $byPerson;

            return ['employee_id' => $id, 'problem' => $id === null ? 'unknown_employee' : null];
        };
    }

    /**
     * The source system's mappings by code. Codes the file uses that the
     * system has no mapping for yet are added unmapped, so they show up in
     * the settings and can be mapped once for all later files.
     *
     * @return Collection<string, PayrollImportMapping>
     */
    private function mappings(int $sourceId, PayrollFile $file): Collection
    {
        $seen = [];

        foreach ($file->lines as $line) {
            $code = $line->code === null ? null : mb_strtoupper(trim($line->code));

            if ($code !== null && $code !== '' && mb_strlen($code) <= 40 && ! PaxmlCodes::isAbsence($line) && ! PaxmlCodes::isBalance($line)) {
                $seen[$code] ??= $line->description === null ? null : mb_substr($line->description, 0, 120);
            }
        }

        $mappings = PayrollImportMapping::query()->where('payroll_import_source_id', $sourceId)->get()->keyBy('external_code');
        $companyId = CurrentCompany::id();
        $now = now();
        $new = [];

        foreach ($seen as $code => $description) {
            if (! $mappings->has($code)) {
                $new[] = ['company_id' => $companyId, 'payroll_import_source_id' => $sourceId, 'external_code' => $code, 'description' => $description, 'ignored' => false, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        if ($new === []) {
            return $mappings;
        }

        PayrollImportMapping::query()->insertOrIgnore($new);

        return PayrollImportMapping::query()->where('payroll_import_source_id', $sourceId)->get()->keyBy('external_code');
    }

    /** Hours or units with at most three decimals, as text; false when it is not such a number. */
    private static function quantity(?string $value): string|false|null
    {
        if ($value === null) {
            return null;
        }

        try {
            $number = BigDecimal::of($value)->strippedOfTrailingZeros();
        } catch (MathException) {
            return false;
        }

        if ($number->getScale() > 3 || $number->abs()->isGreaterThan(999_999_999)) {
            return false;
        }

        return (string) $number->toScale(3);
    }

    /** Kronor in the file as whole öre; false when it is not an amount in öre. */
    private static function ore(?string $value): int|false|null
    {
        if ($value === null) {
            return null;
        }

        try {
            $ore = BigDecimal::of($value)->multipliedBy(100)->toScale(0);
        } catch (MathException) {
            return false;
        }

        return $ore->abs()->isGreaterThan(intdiv(PHP_INT_MAX, 1000)) ? false : $ore->toInt();
    }

    private static function negative(?string $quantity): bool
    {
        return $quantity !== null && BigDecimal::of($quantity)->isNegative();
    }

    private static function validDate(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value)) + [0, 0, 0];

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate($month, $day, $year);
    }

    private static function maskedPersonalNumber(string $value): ?string
    {
        $number = PersonalNumber::tryFrom($value);

        return $number === null ? null : PersonalNumber::masked($number->digits);
    }

    private function import(PayrollRun $run, PayrollFileImport $import): PayrollFileImport
    {
        return PayrollFileImport::query()->where('payroll_run_id', $run->id)->findOrFail($import->id);
    }

    /** The run as the database holds it, locked until the transaction ends. */
    private static function locked(PayrollRun $run): PayrollRun
    {
        return PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
    }

    private function open(User $user, PayrollRun $run): Company
    {
        $run = PayrollRun::query()->findOrFail($run->id);
        $company = Company::query()->findOrFail($run->company_id);
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! Gate::forUser($user)->allows('update', $run)) {
            throw new AuthorizationException(__('Lönekörningen är attesterad eller så saknar du behörighet att ändra den.'));
        }

        return $company;
    }
}
