<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Signing\Seal\EnvelopeTimestamper;
use App\Models\SigningRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Removes work directories the PDF engine left behind (ADR-046 amendment
 * 2026-11-18, S5). Each call removes its own directory when it ends; one is
 * left only when PHP itself died mid-call (a killed worker, a fatal error),
 * and it may hold a copy of a document being signed. Anything older than an
 * hour, far beyond the engine's timeout, is removed. Only directories with
 * the engine's own random names are touched.
 *
 * Also long-term validation files (ADR-046 S11) on the signing disk that no
 * version row holds and that are older than a day: an attempt that was
 * interrupted between writing its file and storing its row. Only names that
 * EnvelopeTimestamper::newPath gives are touched, and each is checked again
 * under its envelope's row lock before it is deleted.
 */
class PruneSigningEngineWorkCommand extends Command
{
    public const int MAX_AGE_SECONDS = 3600;

    /** A long-term validation file without a version row is removed after a day (ADR-046 S11). */
    public const int LTV_MAX_AGE_SECONDS = 86400;

    protected $signature = 'signing:prune-engine-work';

    protected $description = 'Tar bort PDF-motorns tillfälliga arbetsmappar som är äldre än en timme.';

    public function handle(): int
    {
        $this->pruneLongTermLeftovers();

        $root = (string) config('nonox.signing.pdf_engine.work_dir');

        if ($root === '' || ! is_dir($root)) {
            $this->comment('Ingen arbetsmapp för PDF-motorn finns.');

            return self::SUCCESS;
        }

        $removed = 0;
        $before = time() - self::MAX_AGE_SECONDS;

        foreach (File::directories($root) as $directory) {
            if (preg_match('/^[0-9a-f]{32}$/', basename($directory)) !== 1 || is_link($directory)) {
                continue;
            }

            $modified = filemtime($directory);

            if ($modified === false || $modified >= $before) {
                continue;
            }

            File::deleteDirectory($directory);
            $removed++;
        }

        $this->comment("Tog bort {$removed} kvarlämnade arbetsmappar.");

        return self::SUCCESS;
    }

    private function pruneLongTermLeftovers(): void
    {
        $disk = Storage::disk(SigningRequest::DISK);
        $before = time() - self::LTV_MAX_AGE_SECONDS;
        $removed = 0;

        foreach ($disk->directories('crm-signing') as $company) {
            if (preg_match('#^crm-signing/(\d+)$#', $company, $match) !== 1) {
                continue;
            }

            $directory = EnvelopeTimestamper::directory((int) $match[1]);

            if (! $disk->directoryExists($directory)) {
                continue;
            }

            $files = array_values(array_filter($disk->allFiles($directory), fn (string $path): bool => EnvelopeTimestamper::isLongTermFile($path)));

            // Held paths are filtered out in the database first, in chunks; the age is asked only of the rest.
            foreach (array_chunk($files, 500) as $chunk) {
                $held = DB::table('signing_document_versions')->whereIn('path', $chunk)->pluck('path')->all();

                foreach (array_diff($chunk, $held) as $path) {
                    if ($disk->lastModified($path) >= $before) {
                        continue;
                    }

                    $removed += $this->deleteIfUnheld($path) ? 1 : 0;
                }
            }
        }

        $this->comment("Tog bort {$removed} kvarlämnade filer från långtidsvalidering.");
    }

    /**
     * Under the envelope's row lock, which an attempt holds while it stores its version: deleted only when no row
     * holds the path then. Across companies on purpose: the path names its envelope.
     */
    private function deleteIfUnheld(string $path): bool
    {
        $envelopeId = EnvelopeTimestamper::envelopeOf($path);

        if ($envelopeId === null) {
            return false;
        }

        return DB::transaction(function () use ($path, $envelopeId): bool {
            DB::table('signing_envelopes')->where('id', $envelopeId)->lockForUpdate()->first(['id']);

            if (DB::table('signing_document_versions')->where('path', $path)->exists()) {
                return false;
            }

            Storage::disk(SigningRequest::DISK)->delete($path);

            return true;
        });
    }
}
