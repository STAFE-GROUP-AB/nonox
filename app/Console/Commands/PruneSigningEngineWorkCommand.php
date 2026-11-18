<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Removes work directories the PDF engine left behind (ADR-046 amendment
 * 2026-11-18, S5). Each call removes its own directory when it ends; one is
 * left only when PHP itself died mid-call (a killed worker, a fatal error),
 * and it may hold a copy of a document being signed. Anything older than an
 * hour, far beyond the engine's timeout, is removed. Only directories with
 * the engine's own random names are touched.
 */
class PruneSigningEngineWorkCommand extends Command
{
    public const int MAX_AGE_SECONDS = 3600;

    protected $signature = 'signing:prune-engine-work';

    protected $description = 'Tar bort PDF-motorns tillfälliga arbetsmappar som är äldre än en timme.';

    public function handle(): int
    {
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
}
