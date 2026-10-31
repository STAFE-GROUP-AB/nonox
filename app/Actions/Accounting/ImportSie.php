<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Domain\Accounting\Dimension;
use App\Domain\Accounting\LedgerBalances;
use App\Domain\Accounting\LedgerHash;
use App\Domain\Accounting\SieImport\SieImportAnalyser;
use App\Domain\Accounting\SieImport\SieImportPlan;
use App\Domain\Accounting\SieImport\SieImportRefused;
use App\Domain\Accounting\SieImport\SieImportStatus;
use App\Domain\Accounting\SieImport\SieTransaction;
use App\Domain\Accounting\SieImport\SieVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\ImportedVoucher;
use App\Models\OpeningBalance;
use App\Models\SieImport;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherNumberCounter;
use App\Models\VoucherRow;
use App\Models\VoucherRowHistory;
use App\Models\VoucherSeries;
use App\Support\Audit\Audit;
use App\Support\Files\PrivateFile;
use App\Support\Sie\SieReader;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reads a confirmed SIE file into the bookkeeping (AGENTS.md §9.2), all or
 * nothing, inside one transaction:
 *
 * 1. Lock the company, so imports never run side by side, and check the file
 *    again: the bookkeeping may have changed since the user saw the report.
 * 2. Create the fiscal year, accounts, series, cost centres and projects
 *    the report listed.
 * 3. Write the opening balances (#IB 0).
 * 4. Post every voucher (source IMPORT_SIE) in the file's order, chained into
 *    the fiscal year's hash chain, keeping the file's numbers where the
 *    report said so, and record where each voucher came from.
 * 5. Reconcile: every closing balance and result must equal the file's. A
 *    difference rolls everything back, because posted vouchers can never be
 *    removed afterwards.
 */
class ImportSie
{
    public const string DISK = 'local';

    private const int PROGRESS_EVERY = 25;

    /** @var array<string, array<string, int>> dimension => code => id */
    private array $objects = [];

    public function __construct(private readonly SieImportAnalyser $analyser) {}

    public static function progressKey(SieImport $import): string
    {
        return "sie-import:{$import->id}:progress";
    }

    /**
     * @throws SieImportRefused
     */
    public function __invoke(SieImport $import): void
    {
        $company = Company::query()->findOrFail($import->company_id);
        $user = User::query()->findOrFail($import->user_id);

        if (! $user->hasTeamPermission($company, 'import:sie')) {
            throw new SieImportRefused([__('Du har inte längre behörighet att läsa in SIE-filer i företaget.')]);
        }

        // The stored file is encrypted; the parser reads a decrypted temporary copy, removed afterwards.
        PrivateFile::withLocalCopy(self::DISK, $import->file_path, fn (string $path) => $this->import($import, $company, $user, $path));
    }

    /**
     * @throws SieImportRefused
     */
    private function import(SieImport $import, Company $company, User $user, string $path): void
    {
        CurrentCompany::run($company, fn () => DB::transaction(function () use ($import, $company, $user, $path): void {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            $plan = $this->analyser->analyse($company, $path, $import->sha256, $import->id);

            if ($plan->blockers !== []) {
                throw new SieImportRefused($plan->blockers);
            }

            $fiscalYear = $this->fiscalYear($plan, $company, $user);
            $accounts = $this->accounts($plan, $company, $user);
            $series = $this->series($plan);
            $this->objects = $this->dimensionObjects($plan, $company, $user);

            $this->openingBalances($plan, $fiscalYear, $accounts);

            $imported = $this->vouchers($import, $plan, $path, $fiscalYear, $accounts, $series, $user);
            $result = $this->reconcile($plan, $fiscalYear->fresh() ?? $fiscalYear, $accounts, $imported);

            if (! $result['ok']) {
                throw new SieImportRefused([__('Avstämningen mot filen stämmer inte, så ingenting har lästs in.')], $result);
            }

            $import->forceFill([
                'status' => SieImportStatus::Completed,
                'fiscal_year_id' => $fiscalYear->id,
                'result' => $result,
                'error' => null,
                'finished_at' => CarbonImmutable::now(),
            ])->save();

            // TODO(legal-verify): one audit entry per import, not per voucher; imported_vouchers holds each voucher's origin.
            Audit::record('sie.imported', $import, [
                'file' => $import->original_name,
                'sha256' => $import->sha256,
                'fiscal_year' => $fiscalYear->label(),
                'vouchers' => $imported,
                'accounts_created' => array_keys($plan->accountsToCreate),
                'opening_balances' => $plan->writeOpeningBalances ? count($plan->openingBalances) : 0,
            ], $company, $user);
        }));
    }

    private function fiscalYear(SieImportPlan $plan, Company $company, User $user): FiscalYear
    {
        if (! $plan->createFiscalYear) {
            return FiscalYear::query()->whereKey($plan->fiscalYearId)->lockForUpdate()->firstOrFail();
        }

        $fiscalYear = FiscalYear::create(['start_date' => $plan->start, 'end_date' => $plan->end]);

        Audit::record('fiscal_year.created', $fiscalYear, [
            'start_date' => $fiscalYear->start_date->toDateString(),
            'end_date' => $fiscalYear->end_date->toDateString(),
            'source' => 'sie_import',
        ], $company, $user);

        return $fiscalYear;
    }

    /**
     * @return Collection<int, Account> keyed by account number
     */
    private function accounts(SieImportPlan $plan, Company $company, User $user): Collection
    {
        foreach ($plan->accountsToCreate as $number => $account) {
            $created = Account::create([
                'number' => $number,
                'name' => $account['name'],
                'type' => $account['type'],
                'sru_code' => $account['sru'],
            ]);

            Audit::record('account.created', $created, [
                'number' => $created->number,
                'name' => $created->name,
                'type' => $created->type->value,
                'source' => 'sie_import',
            ], $company, $user);
        }

        return Account::query()->get()->keyBy('number');
    }

    /**
     * @return Collection<string, VoucherSeries> keyed by code
     */
    private function series(SieImportPlan $plan): Collection
    {
        foreach ($plan->series as $code => $series) {
            if ($series['create']) {
                VoucherSeries::create(['code' => (string) $code, 'name' => __('Inläst serie :code', ['code' => $code])]);
            }
        }

        /** @var Collection<string, VoucherSeries> */
        return VoucherSeries::query()->get()->keyBy('code');
    }

    /**
     * Creates the cost centres and projects the report listed.
     *
     * @return array<string, array<string, int>>
     */
    private function dimensionObjects(SieImportPlan $plan, Company $company, User $user): array
    {
        $ids = [];

        foreach (Dimension::cases() as $dimension) {
            foreach ($plan->objectsToCreate[$dimension->value] ?? [] as $code => $name) {
                $created = $dimension->model()::create(['code' => (string) $code, 'name' => $name]);

                Audit::record('dimension.created', $created, [
                    'dimension' => $dimension->value,
                    'code' => $created->code,
                    'name' => $created->name,
                    'source' => 'sie_import',
                ], $company, $user);
            }

            $ids[$dimension->value] = $dimension->model()::query()->pluck('id', 'code')->map(fn (mixed $id): int => (int) $id)->all();
        }

        return $ids;
    }

    /**
     * The id of the cost centre or project a SIE row refers to.
     */
    private function objectId(SieTransaction $row, Dimension $dimension): ?int
    {
        $code = $row->objects[$dimension->sieNumber()] ?? null;

        return $code === null ? null : ($this->objects[$dimension->value][$code] ?? null);
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function openingBalances(SieImportPlan $plan, FiscalYear $fiscalYear, Collection $accounts): void
    {
        if (! $plan->writeOpeningBalances) {
            return;
        }

        foreach ($plan->openingBalances as $number => $amount) {
            OpeningBalance::create([
                'fiscal_year_id' => $fiscalYear->id,
                'account_id' => $accounts->get($number)?->id,
                'amount_ore' => $amount,
            ]);
        }
    }

    /**
     * Posts the file's vouchers in the file's order. Reads the file again
     * rather than keeping every voucher in memory.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  Collection<string, VoucherSeries>  $series
     */
    private function vouchers(SieImport $import, SieImportPlan $plan, string $path, FiscalYear $fiscalYear, Collection $accounts, Collection $series, User $user): int
    {
        $defaultSeries = (string) $series->first(fn (VoucherSeries $item): bool => $item->is_default)?->code;
        $accountsById = $accounts->keyBy('id');
        $next = array_map(fn (array $item): int => $item['first'], $plan->series);
        $head = $fiscalYear->ledger_head_hash;
        $postedAt = CarbonImmutable::now()->startOfSecond();
        $count = 0;

        foreach ((new SieReader($path))->records() as $record) {
            if ($record->label !== '#VER') {
                continue;
            }

            $sieVoucher = SieVoucher::fromRecord($record);
            $code = $sieVoucher->series !== '' ? $sieVoucher->series : $defaultSeries;
            $voucherSeries = $series->get($code) ?? throw new SieImportRefused([__('Serie :code saknas.', ['code' => $code])]);

            $number = $plan->series[$code]['keep'] ? (int) $sieVoucher->number : $next[$code];
            $next[$code] = max($next[$code], $number + 1);

            $voucher = $this->post($sieVoucher, $fiscalYear, $voucherSeries, $number, $accounts, $accountsById, $user, $postedAt, $head);
            $head = $voucher->hash;

            ImportedVoucher::create([
                'sie_import_id' => $import->id,
                'voucher_id' => $voucher->id,
                'fiscal_year_id' => $fiscalYear->id,
                'original_series' => $code,
                'original_number' => $sieVoucher->number !== '' ? $sieVoucher->number : (string) $number,
                'registered_on' => $sieVoucher->registeredOn,
                'signature' => $sieVoucher->signature,
            ]);

            $this->history($sieVoucher, $voucher);

            if (++$count % self::PROGRESS_EVERY === 0) {
                Cache::put(self::progressKey($import), ['done' => $count, 'total' => $plan->vouchers], now()->addHour());
            }
        }

        $fiscalYear->forceFill(['ledger_head_hash' => $head])->save();
        $this->counters($fiscalYear, $series, $next);

        return $count;
    }

    /**
     * Inserts the voucher as a draft with its rows, then marks it posted with
     * its number and hash, as PostVoucher does. The database checks the
     * balance once more when it becomes posted.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  Collection<int, Account>  $accountsById
     */
    private function post(SieVoucher $sieVoucher, FiscalYear $fiscalYear, VoucherSeries $series, int $number, Collection $accounts, Collection $accountsById, User $user, CarbonImmutable $postedAt, ?string $previousHash): Voucher
    {
        $voucher = Voucher::create([
            'fiscal_year_id' => $fiscalYear->id,
            'series_id' => $series->id,
            'voucher_date' => $sieVoucher->date,
            'description' => $sieVoucher->text,
            'source' => VoucherSource::ImportSie,
            'created_by' => $user->id,
        ]);

        $now = CarbonImmutable::now();

        VoucherRow::query()->insert(array_map(fn (SieTransaction $row): array => [
            'company_id' => $voucher->company_id,
            'voucher_id' => $voucher->id,
            'account_id' => $accounts->get($row->account)?->id,
            'debit_ore' => $row->debit(),
            'credit_ore' => $row->credit(),
            'description' => $row->text,
            'quantity' => $row->quantity,
            'cost_center_id' => $this->objectId($row, Dimension::CostCenter),
            'project_id' => $this->objectId($row, Dimension::Project),
            'created_at' => $now,
            'updated_at' => $now,
        ], $sieVoucher->rows));

        $rows = VoucherRow::query()->where('voucher_id', $voucher->id)->orderBy('id')->get();
        $rows->each(fn (VoucherRow $row) => $row->setRelation('account', $accountsById->get($row->account_id)));

        $voucher->setRelation('rows', $rows);
        $voucher->setRelation('series', $series);

        $voucher->forceFill([
            'number' => $number,
            'status' => VoucherStatus::Posted,
            'posted_at' => $postedAt,
            'posted_by' => $user->id,
            'previous_hash' => $previousHash,
        ]);

        $voucher->hash = LedgerHash::of($voucher, $previousHash);
        $voucher->save();

        return $voucher;
    }

    private function history(SieVoucher $sieVoucher, Voucher $voucher): void
    {
        $entries = [];

        foreach ($sieVoucher->rows as $position => $row) {
            if ($row->label === '#RTRANS') {
                $entries[] = [$row, VoucherRowHistory::ADDED, $position, $voucher->rows->get($position)?->id];
            }
        }

        foreach ($sieVoucher->removed as $removed) {
            $entries[] = [$removed['row'], VoucherRowHistory::REMOVED, $removed['after'], null];
        }

        foreach ($entries as [$row, $change, $position, $rowId]) {
            VoucherRowHistory::create([
                'voucher_id' => $voucher->id,
                'voucher_row_id' => $rowId,
                'change' => $change,
                'position' => $position,
                'account_number' => $row->account,
                'amount_ore' => $row->amount,
                'transaction_date' => $row->date,
                'description' => $row->text,
                'quantity' => $row->quantity,
                'signature' => $row->signature,
                'cost_center_id' => $this->objectId($row, Dimension::CostCenter),
                'project_id' => $this->objectId($row, Dimension::Project),
            ]);
        }
    }

    /**
     * @param  Collection<string, VoucherSeries>  $series
     * @param  array<string, int>  $next
     */
    private function counters(FiscalYear $fiscalYear, Collection $series, array $next): void
    {
        foreach ($next as $code => $nextNumber) {
            $seriesId = $series->get((string) $code)?->id;

            $counter = VoucherNumberCounter::query()
                ->where('fiscal_year_id', $fiscalYear->id)
                ->where('series_id', $seriesId)
                ->lockForUpdate()
                ->first();

            if ($counter instanceof VoucherNumberCounter) {
                $counter->update(['next_number' => max($counter->next_number, $nextNumber)]);

                continue;
            }

            VoucherNumberCounter::create(['fiscal_year_id' => $fiscalYear->id, 'series_id' => $seriesId, 'next_number' => $nextNumber]);
        }
    }

    /**
     * Compares every closing balance (#UB 0) and result (#RES 0) in the file
     * with the bookkeeping here after the import.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array{ok: bool, vouchers_in_file: int, vouchers_imported: int, checked_closing: bool, checked_results: bool, differences: list<array{account: int, kind: string, file: int, here: int}>}
     */
    private function reconcile(SieImportPlan $plan, FiscalYear $fiscalYear, Collection $accounts, int $imported): array
    {
        $opening = LedgerBalances::opening($fiscalYear);
        $movements = LedgerBalances::movements($fiscalYear);
        $closingHere = [];
        $resultsHere = [];

        foreach ($accounts as $number => $account) {
            $movement = (int) ($movements[$account->id] ?? 0);

            if (LedgerBalances::isBalanceSheet($account->type)) {
                $closingHere[$number] = (int) ($opening[$account->id] ?? 0) + $movement;
            } else {
                $resultsHere[$number] = $movement;
            }
        }

        $differences = [
            ...($plan->closingBalances === [] ? [] : $this->differences('UB', $plan->closingBalances, $closingHere)),
            ...($plan->results === [] ? [] : $this->differences('RES', $plan->results, $resultsHere)),
        ];

        return [
            'ok' => $differences === [] && $imported === $plan->vouchers,
            'vouchers_in_file' => $plan->vouchers,
            'vouchers_imported' => $imported,
            'checked_closing' => $plan->closingBalances !== [],
            'checked_results' => $plan->results !== [],
            'differences' => $differences,
        ];
    }

    /**
     * @param  array<int, int>  $file
     * @param  array<int, int>  $here
     * @return list<array{account: int, kind: string, file: int, here: int}>
     */
    private function differences(string $kind, array $file, array $here): array
    {
        $numbers = array_unique([...array_keys($file), ...array_keys(array_filter($here))]);
        sort($numbers);

        $differences = [];

        foreach ($numbers as $number) {
            $theirs = $file[$number] ?? 0;
            $ours = $here[$number] ?? 0;

            if ($theirs !== $ours) {
                $differences[] = ['account' => $number, 'kind' => $kind, 'file' => $theirs, 'here' => $ours];
            }
        }

        return $differences;
    }
}
