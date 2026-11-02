<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Build\VersionRecorder;
use Illuminate\Console\Command;

/**
 * Records the program version (#43) and the systemdokumentation (#44) in
 * use when they have changed. Safe to
 * run any number of times; it records only what is new.
 */
class RecordVersionCommand extends Command
{
    protected $signature = 'nonox:record-version';

    protected $description = 'Registrerar programversionen och systemdokumentationen som är i bruk om de har ändrats.';

    public function handle(): int
    {
        $recorded = VersionRecorder::record();

        $this->info($recorded['program'] === null
            ? 'Programversionen är redan registrerad.'
            : 'Programversion '.($recorded['program']->payload['version'] ?? '?').' registrerad i behandlingshistoriken.');

        $this->info($recorded['documentation'] === null
            ? 'Systemdokumentationen är redan sparad.'
            : "Systemdokumentation {$recorded['documentation']->version} sparad.");

        return self::SUCCESS;
    }
}
