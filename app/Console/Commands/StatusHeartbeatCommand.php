<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Status\Heartbeats;
use Illuminate\Console\Command;

/**
 * Lets the operator's own scripts tell the public status page (ADR-078)
 * that a backup or a restore test ran. Nothing else is accepted: the page
 * shows only what the instance can know.
 */
class StatusHeartbeatCommand extends Command
{
    protected $signature = 'status:heartbeat {key : backup eller restore-test} {--failed : Körningen misslyckades}';

    protected $description = 'Registrerar att en säkerhetskopia eller ett återställningstest har körts, för statussidan';

    public function handle(): int
    {
        $key = (string) $this->argument('key');

        if (! in_array($key, Heartbeats::REPORTED, true)) {
            $this->error('Okänd nyckel. Giltiga nycklar: '.implode(', ', Heartbeats::REPORTED).'.');

            return self::INVALID;
        }

        Heartbeats::record($key, ! $this->option('failed'));
        $this->info($this->option('failed') ? "Misslyckad körning av {$key} registrerad." : "Lyckad körning av {$key} registrerad.");

        return self::SUCCESS;
    }
}
