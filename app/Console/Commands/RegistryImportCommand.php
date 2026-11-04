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
use Illuminate\Support\Facades\Cache;
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
 * legal persons are loaded. After a complete SCB run, companies no longer
 * in it are deleted. Changes of companies that some CRM watches are
 * recorded for crm:registry-watch, and kept 400 days.
 */
class RegistryImportCommand extends Command
{
    public const int CHUNK = 1000;

    public const int KEEP_CHANGES_DAYS = 400;

    /** A complete run has at least this share of the companies in the register; a smaller one (a cut download) deletes nothing. */
    public const float GONE_MIN_SHARE = 0.8;

    protected $signature = 'registry:import
        {source : scb-bulk, bolagsverket-bulk eller scb-api}
        {--file= : En nedladdad fil (zip eller txt) i stället för att hämta den}
        {--force : Läs filen även om den inte har ändrats sedan förra körningen}
        {--resume : Fortsätt en körning av samma fil som inte blev klar}';

    protected $description = 'Läser in företagsregistret (juridiska personer) från SCB:s och Bolagsverkets öppna data.';

    /** A company is removed after this many complete SCB runs in a row without it. */
    public const int MISSED_RUNS_TO_REMOVE = 2;

    /** @var array<string, true> the organisation numbers some company watches */
    private array $watched = [];

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

        $this->watched = $this->watched();
        $writer = new RegistryWriter($source, $import, $this->watched);
        // One import at a time, whichever source: they share rows, the sources list and the change log.
        $lock = Cache::lock('registry-import', 6 * 3600);

        if (! $lock->get()) {
            $import->forceFill(['status' => RegistryImport::FAILED, 'error' => 'Another import was running.'])->save();
            $this->error('En annan inläsning av registret pågår. Försök igen när den är klar (eller fortsätt med --resume).');

            return self::FAILURE;
        }

        try {
            $this->load($source, $import, $writer);
            // Still under the lock: a complete SCB run (file or API) removes the companies it no longer has.
            $gone = $source->createsRows() ? $this->removeGone($import) : 0;

            if ($source->key() === ScbBulkFile::KEY) {
                $this->checkOptOuts($import);
            }

            $import->forceFill(['status' => RegistryImport::DONE, 'finished_at' => now()])->save();
            $pruned = RegistryChange::query()->where('detected_at', '<', CarbonImmutable::now()->subDays(self::KEEP_CHANGES_DAYS))->delete();
        } catch (Throwable $exception) {
            $import->forceFill(['status' => RegistryImport::FAILED, 'error' => mb_substr($exception->getMessage(), 0, 1000)])->save();
            $this->error("Körningen avbröts: {$exception->getMessage()} Fortsätt med --resume.");

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $this->comment("Klart: {$import->rows_read} rader lästa, {$import->rows_written} företag skrivna, {$import->rows_skipped} hoppade över (fysiska personer och ofullständiga rader), {$import->changes} ändringar hos bevakade företag, {$gone} borttagna ur registret, {$pruned} gamla ändringar rensade.");

        return self::SUCCESS;
    }

    /**
     * The opt-out mapping of the bulk file is not documented by SCB
     * (TODO(legal-verify), ADR-047): if most companies of this run come
     * out as opted out, the mapping is probably inverted. Warn and keep the
     * warning on the import row, for the operator to check.
     */
    private function checkOptOuts(RegistryImport $import): void
    {
        $counts = DB::selectOne('SELECT COUNT(*) FILTER (WHERE advertising_block) AS blocked, COUNT(*) AS total FROM company_registry WHERE last_import_id = ?', [$import->id]);
        $blocked = (int) ($counts->blocked ?? 0);
        $total = (int) ($counts->total ?? 0);

        if ($total > 0 && $blocked * 2 > $total) {
            $warning = "{$blocked} av {$total} företag har reklamspärr enligt filen. Det är troligen fel tolkning av kolumnen Reklamsparrtyp; kontrollera med SCB (scbforetag@scb.se) innan registret används.";
            $import->forceFill(['warning' => $warning])->save();
            $this->warn($warning);
        }
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
     * Companies a complete SCB run (file or API) did not have get one more
     * missed run; those missing from MISSED_RUNS_TO_REMOVE runs in a row have
     * left SCB's register and are deleted (ADR-047), so a difference between
     * the file and the API, or one bad week, deletes nothing. A run with far
     * fewer companies than the register (a cut download) counts nothing.
     *
     * A watched company that is deleted gets the change "removed", kept
     * until crm:registry-watch has told the companies that have it.
     */
    private function removeGone(RegistryImport $import): int
    {
        $present = DB::table('company_registry')->count();

        if ($present > 0 && $import->rows_written < $present * self::GONE_MIN_SHARE) {
            $this->warn("Källan hade bara {$import->rows_written} av {$present} företag. Inga tas bort.");

            return 0;
        }

        DB::table('company_registry')
            ->where(fn ($query) => $query->whereNull('last_import_id')->orWhere('last_import_id', '<>', $import->id))
            ->increment('missed_runs');

        $gone = DB::table('company_registry')
            ->where('missed_runs', '>=', self::MISSED_RUNS_TO_REMOVE)
            ->get(['org_number', 'name']);
        $now = CarbonImmutable::now();

        foreach ($gone->chunk(1000) as $chunk) {
            $removed = $chunk->filter(fn (object $row): bool => isset($this->watched[(string) $row->org_number]))
                ->map(fn (object $row): array => ['org_number' => (string) $row->org_number, 'field' => 'removed', 'old_value' => mb_substr((string) $row->name, 0, 500), 'new_value' => null, 'source' => (string) $import->source, 'import_id' => $import->id, 'detected_at' => $now])
                ->values()
                ->all();

            $orgs = array_values($chunk->map(fn (object $row): string => (string) $row->org_number)->all());
            $watched = array_values(array_filter($orgs, fn (string $org): bool => isset($this->watched[$org])));

            DB::transaction(function () use ($orgs, $watched, $removed): void {
                // A watched company's changes stay until every company has been told; the rest go now.
                RegistryWriter::forget(array_values(array_diff($orgs, $watched)));
                RegistryWriter::forget($watched, keepUnnoticed: true);

                if ($removed !== []) {
                    DB::table('company_registry_changes')->insert($removed);
                }
            });
        }

        return $gone->count();
    }

    /**
     * The organisation numbers some company watches: its customers, and its
     * prospects when it has "Bevaka även prospekt" on. Only their changes are
     * recorded. Read across companies on purpose, ten digits only; nothing
     * about the accounts leaves this method.
     *
     * @return array<string, true>
     */
    private function watched(): array
    {
        $watched = [];

        DB::table('crm_accounts')
            ->whereNotNull('crm_accounts.org_number')
            ->where(fn ($query) => $query->whereNotNull('crm_accounts.customer_id')->orWhereExists(fn ($modules) => $modules->select(DB::raw(1))
                ->from('company_modules')
                ->whereColumn('company_modules.company_id', 'crm_accounts.company_id')
                ->where('company_modules.module_key', 'crm')
                ->whereRaw("(company_modules.settings->>'registry_monitoring_prospects') = '1'")))
            ->select('crm_accounts.org_number')->distinct()->orderBy('crm_accounts.org_number')
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
