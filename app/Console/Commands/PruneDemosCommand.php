<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Demo\PurgeExpiredDemos;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Clears out the demo database (ADR-077, addendum 2026-11-08): when no demo is
 * live and some have expired it drops the demo database's schema and builds
 * it again. It runs only against the "demo" connection and refuses unless
 * that database is not the main one and its name ends in _demo. It runs also
 * when "Prova utan att registrera" is switched off, so nothing is left behind.
 */
class PruneDemosCommand extends Command
{
    protected $signature = 'demo:prune';

    protected $description = 'Bygger demodatabasen på nytt när inga demor är igång och några har gått ut. Rör ingen annan databas.';

    public function handle(PurgeExpiredDemos $purge): int
    {
        try {
            $result = ($purge)();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result['rebuilt']) {
            $this->comment("Demodatabasen är byggd på nytt ({$result['expired']} utgångna demor borta).");

            return self::SUCCESS;
        }

        $this->comment("Inget att bygga om: {$result['live']} demor är igång, {$result['expired']} har gått ut".($result['draining'] ? ', och nya demor väntar tills de är klara.' : '.'));

        return self::SUCCESS;
    }
}
