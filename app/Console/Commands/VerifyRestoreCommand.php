<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Accounting\LedgerVerifier;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Modules\StaffLedger\Domain\ChainVerifier;
use App\Modules\StaffLedger\Models\Entry;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Checks a restored backup (docs/gdpr/sakerhetskopiering.md): the database
 * answers, every company's ledger and staff ledger chains are intact, every
 * voucher attachment matches its checksum and every supplier invoice file is
 * there.
 *
 * It points the default connection and the private disk at the restored copy
 * for the rest of the process, so nothing it reads or writes reaches the live
 * installation. scripts/restore-test.sh restores the copy and runs this.
 */
class VerifyRestoreCommand extends Command
{
    protected $signature = 'backup:verify-restore
        {--files= : Den uppackade kopian av storage/app/private}
        {--connection= : En befintlig databasanslutning att kontrollera i stället för värd och databas}
        {--host= : Databasens värd eller unix-socketkatalog}
        {--port=5432 : Databasens port}
        {--database= : Den återställda databasen}
        {--username= : Användaren för den återställda databasen}';

    protected $description = 'Kontrollerar en återställd säkerhetskopia: databasen, bokföringen och underlagen';

    /** @var list<string> */
    private const array TABLES = ['teams', 'users', 'vouchers', 'voucher_rows', 'voucher_attachments', 'supplier_invoices', 'staff_ledger_entries', 'audit_logs'];

    public function handle(LedgerVerifier $verifier, Migrator $migrator): int
    {
        $files = $this->option('files');

        if (! is_string($files) || ! is_dir($files)) {
            $this->error('Ange katalogen med de återställda filerna med --files.');

            return self::INVALID;
        }

        $connection = $this->connection();

        if ($connection === null) {
            $this->error('Ange --connection eller --database för den återställda databasen.');

            return self::INVALID;
        }

        config([
            'database.default' => $connection,
            'filesystems.disks.local.root' => realpath($files),
        ]);
        Storage::forgetDisk('local');

        $failed = 0;

        $this->line('Databasen:');

        foreach (self::TABLES as $table) {
            $this->line(sprintf('  %-20s %d rader', $table, DB::table($table)->count()));
        }

        $failed += $this->checkMigrations($migrator);

        $this->line('Bokföringen:');

        $companies = Company::query()->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->error('  Kopian innehåller inga företag.');
            $failed++;
        }

        foreach ($companies as $company) {
            $result = $verifier->verify($company);

            if ($result['ok']) {
                $this->info("  {$company->name}: {$result['vouchers']} verifikationer och deras underlag är intakta.");

                continue;
            }

            $failed++;

            foreach ($result['problems'] as $problem) {
                $this->error("  {$company->name}: {$problem}");
            }
        }

        $failed += $this->checkStaffLedgers();
        $failed += $this->checkSupplierInvoiceFiles();

        $this->comment($failed === 0
            ? 'Återställningen är godkänd.'
            : "Återställningen är underkänd: {$failed} avvikelser.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function connection(): ?string
    {
        $existing = $this->option('connection');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $database = $this->option('database');

        if (! is_string($database) || $database === '') {
            return null;
        }

        $base = config('database.connections.'.config('database.default'));

        DB::purge('restore');

        config(['database.connections.restore' => array_merge(is_array($base) ? $base : [], [
            'driver' => 'pgsql',
            'url' => null,
            'host' => $this->option('host') ?: '127.0.0.1',
            'port' => $this->option('port'),
            'database' => $database,
            'username' => $this->option('username') ?: get_current_user(),
            'password' => '',
        ])]);

        return 'restore';
    }

    /** The copy may be older than the code; it must at least hold a schema. */
    private function checkMigrations(Migrator $migrator): int
    {
        $ran = DB::table('migrations')->pluck('migration')->all();

        if ($ran === []) {
            $this->error('  Kopian saknar databasschema.');

            return 1;
        }

        $paths = array_merge([database_path('migrations')], $migrator->paths());
        $pending = array_diff(array_keys($migrator->getMigrationFiles($paths)), $ran);

        $this->line(sprintf('  %d migreringar körda, %d nyare i koden än i kopian.', count($ran), count($pending)));

        return 0;
    }

    private function checkStaffLedgers(): int
    {
        $verifier = app(ChainVerifier::class);
        $failed = 0;

        // Every company that has written to a staff ledger, as personalliggare:verify picks them.
        $ids = CompanyScope::bypass(fn (): array => Entry::query()->distinct()->pluck('company_id')->all());

        $this->line('Personalliggaren:');

        foreach (Company::query()->whereIn('id', $ids)->orderBy('id')->get() as $company) {
            $result = $verifier->verify($company);

            if ($result['ok']) {
                $this->info("  {$company->name}: {$result['entries']} poster är intakta.");

                continue;
            }

            $failed++;

            foreach ($result['problems'] as $problem) {
                $this->error("  {$company->name}: {$problem}");
            }
        }

        return $failed;
    }

    private function checkSupplierInvoiceFiles(): int
    {
        $disk = Storage::disk('local');
        $missing = 0;
        $checked = 0;

        // The restored copy holds every company; this check is not one company's view.
        CompanyScope::bypass(function () use ($disk, &$missing, &$checked): void {
            SupplierInvoice::query()->whereNotNull('file_path')->select(['id', 'company_id', 'file_path'])
                ->lazyById()
                ->each(function (SupplierInvoice $invoice) use ($disk, &$missing, &$checked): void {
                    $checked++;

                    if ($disk->exists((string) $invoice->file_path)) {
                        return;
                    }

                    $missing++;
                    $this->error("  Leverantörsfaktura {$invoice->id}: filen saknas.");
                });
        });

        $this->line("Leverantörsfakturor: {$checked} filer kontrollerade, {$missing} saknas.");

        return $missing;
    }
}
