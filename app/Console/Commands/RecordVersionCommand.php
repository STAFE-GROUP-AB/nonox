<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Build\VersionRecorder;
use Illuminate\Console\Command;

/**
 * Records the systemdokumentation in use when it has changed (#44). Safe to
 * run any number of times; it records only what is new.
 */
class RecordVersionCommand extends Command
{
    protected $signature = 'nonox:record-version';

    protected $description = 'Sparar systemdokumentationen som är i bruk om den har ändrats.';

    public function handle(): int
    {
        $recorded = VersionRecorder::record();

        $this->info($recorded['documentation'] === null
            ? 'Systemdokumentationen är redan sparad.'
            : "Systemdokumentation {$recorded['documentation']->version} sparad.");

        return self::SUCCESS;
    }
}
