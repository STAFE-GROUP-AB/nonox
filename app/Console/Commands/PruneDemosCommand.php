<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Demo\PurgeExpiredDemos;
use Illuminate\Console\Command;

/**
 * Removes demo companies and guests whose time has run out (ADR-077). Runs
 * also when "Prova utan att registrera" is switched off, so nothing is left
 * behind. It only ever touches rows flagged as demos.
 */
class PruneDemosCommand extends Command
{
    protected $signature = 'demo:prune';

    protected $description = 'Tar bort demoföretag och gäster vars tid har gått ut.';

    public function handle(PurgeExpiredDemos $purge): int
    {
        $result = ($purge)();

        $this->comment("Tog bort {$result['companies']} demoföretag och {$result['users']} gäster.");

        if ($result['skipped'] > 0) {
            $this->warn("{$result['skipped']} demoföretag lämnades kvar eftersom de inte uppfyller villkoren för att tas bort.");
        }

        return self::SUCCESS;
    }
}
