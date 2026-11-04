<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RegistryChange;
use App\Models\RegistryImport;
use App\Support\CompanyRegistry\Import\BolagsverketBulkFile;
use App\Support\CompanyRegistry\Import\RegistryRow;
use App\Support\CompanyRegistry\Import\RegistrySource;
use App\Support\CompanyRegistry\Import\RegistryWriter;
use App\Support\CompanyRegistry\Import\ScbApi;
use App\Support\CompanyRegistry\Import\ScbBulkFile;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Loads the instance's company register (ADR-047) from one source:
 *
 *   php artisan registry:import scb-bulk            SCB's weekly bulk file (adds and updates companies)
 *   php artisan registry:import bolagsverket-bulk   Bolagsverket's weekly bulk file (registration date, deregistration, bankruptcy …)
 *   php artisan registry:import scb-api             SCB's API, needs SCB_AFR_API_KEY (size classes, municipality, phone …)
 *
 * Streams the source and writes 1 000 rows at a time, so memory stays flat
 * for the whole register. Idempotent; an unchanged file is skipped unless
 * --force; --resume continues an unfinished run of the same file. Only
 * legal persons are loaded. After a complete SCB file, companies no longer
 * in it are marked gone. Changes of companies that some CRM watches are
 * recorded for crm:registry-watch, and kept 400 days.
 */
class RegistryImportCommand extends Command
{
    public const int CHUNK = 1000;

    public const int KEEP_CHANGES_DAYS = 400;

    protected $signature = 'registry:import
        {source : scb-bulk, bolagsverket-bulk eller scb-api}
        {--file= : En nedladdad fil (zip eller txt) i stället för att hämta den}
        {--force : Läs filen även om den inte har ändrats sedan förra körningen}
        {--resume : Fortsätt en körning av samma fil som inte blev klar}';

    protected $description = 'Läser in företagsregistret (juridiska personer) från SCB:s och Bolagsverkets öppna data.';

    public function handle(): int
    {
        try {
            $source = $this->source();
            $version = $source->version();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $done = RegistryImport::query()->where('source', $source->key())->where('status', RegistryImport::DONE)->latest('id')->first();

        if (! $this->option('force') && ! $this->option('resume') && $done !== null && $version !== null && $done->version === $version) {
            $this->comment("Källan {$source->key()} har inte ändrats sedan körning {$done->id}. Inget att läsa (använd --force för att läsa ändå).");

            return self::SUCCESS;
        }

        $import = $this->option('resume')
            ? RegistryImport::query()->where('source', $source->key())->where('version', $version)->whereIn('status', [RegistryImport::RUNNING, RegistryImport::FAILED])->latest('id')->first()
            : null;

        if ($import !== null) {
            $this->info("Fortsätter körning {$import->id} från position {$import->position}...");
            $import->forceFill(['status' => RegistryImport::RUNNING, 'error' => null])->save();
        } else {
            $import = RegistryImport::create(['source' => $source->key(), 'version' => $version, 'status' => RegistryImport::RUNNING, 'started_at' => now()]);
            $this->info("Körning {$import->id}: läser {$source->key()}...");
        }

        $writer = new RegistryWriter($source, $import, $this->watched());

        try {
            $this->load($source, $import, $writer);
        } catch (Throwable $exception) {
            $import->forceFill(['status' => RegistryImport::FAILED, 'error' => mb_substr($exception->getMessage(), 0, 1000)])->save();
            $this->error("Körningen avbröts: {$exception->getMessage()} Fortsätt med --resume.");

            return self::FAILURE;
        }

        $gone = $source->key() === ScbBulkFile::KEY ? $this->markGone($import) : 0;
        $import->forceFill(['status' => RegistryImport::DONE, 'finished_at' => now()])->save();
        $pruned = RegistryChange::query()->where('detected_at', '<', CarbonImmutable::now()->subDays(self::KEEP_CHANGES_DAYS))->delete();

        $this->comment("Klart: {$import->rows_read} rader lästa, {$import->rows_written} företag skrivna, {$import->rows_skipped} hoppade över (fysiska personer och ofullständiga rader), {$import->changes} ändringar hos bevakade företag, {$gone} borta ur källan, {$pruned} gamla ändringar rensade.");

        return self::SUCCESS;
    }

    private function load(RegistrySource $source, RegistryImport $import, RegistryWriter $writer): void
    {
        $chunk = [];
        $read = 0;
        $skipped = 0;

        $flush = function () use (&$chunk, &$read, &$skipped, $source, $import, $writer): void {
            $result = $writer->write($chunk);
            $import->forceFill([
                'rows_read' => $import->rows_read + $read,
                'rows_written' => $import->rows_written + $result['written'],
                'rows_skipped' => $import->rows_skipped + $skipped,
                'changes' => $import->changes + $result['changes'],
                'position' => $source->position(),
            ])->save();
            $chunk = [];
            $read = 0;
            $skipped = 0;

            if ($import->rows_read % 50_000 < self::CHUNK) {
                $this->line("  {$import->rows_read} rader lästa...");
            }
        };

        foreach ($source->rows($import->position) as $row) {
            $read++;

            if ($row instanceof RegistryRow) {
                $chunk[] = $row;
            } else {
                $skipped++;
            }

            if ($read >= self::CHUNK) {
                $flush();
            }
        }

        $flush();
    }

    /**
     * Rows a complete SCB file no longer has. A file with far fewer
     * companies than the register (a truncated download) marks nothing.
     */
    private function markGone(RegistryImport $import): int
    {
        $present = DB::table('company_registry')->whereNull('gone_at')->count();

        if ($present > 0 && $import->rows_written < $present / 2) {
            $this->warn("Filen hade bara {$import->rows_written} av {$present} företag. Inga markeras som borta.");

            return 0;
        }

        return DB::table('company_registry')
            ->whereNull('gone_at')
            ->where(fn ($query) => $query->whereNull('last_import_id')->orWhere('last_import_id', '<>', $import->id))
            ->update(['gone_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The organisation numbers some company's CRM has as an account: only
     * their changes are recorded. Read across companies on purpose, ten
     * digits only; nothing about the accounts leaves this method.
     *
     * @return array<string, true>
     */
    private function watched(): array
    {
        $watched = [];

        DB::table('crm_accounts')->whereNotNull('org_number')->select('org_number')->distinct()->orderBy('org_number')
            ->each(function (object $row) use (&$watched): void {
                $digits = (string) preg_replace('/\D/', '', (string) $row->org_number);

                if (strlen($digits) === 10) {
                    $watched[$digits] = true;
                }
            }, 5000);

        return $watched;
    }

    private function source(): RegistrySource
    {
        $file = $this->option('file');
        $file = is_string($file) && $file !== '' ? $file : null;

        return match ((string) $this->argument('source')) {
            ScbBulkFile::KEY => ScbBulkFile::make($file),
            BolagsverketBulkFile::KEY => BolagsverketBulkFile::make($file),
            ScbApi::KEY => ScbApi::make(),
            default => throw new \InvalidArgumentException('Okänd källa. Välj scb-bulk, bolagsverket-bulk eller scb-api.'),
        };
    }
}
