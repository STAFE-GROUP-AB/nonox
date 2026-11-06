<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Integrations\IntegrationHealth;
use Illuminate\Console\Command;

/**
 * How the bank feed and Peppol are doing on this installation, for the
 * operator and a monitoring cron: exits with a failure when something needs
 * attention (a provider paused, connections that keep failing, sendings
 * that wait). Counts only, never company or personal data.
 */
class IntegrationsStatusCommand extends Command
{
    protected $signature = 'integrations:status {--json : print the status as JSON}';

    protected $description = 'Show the health of the bank feed and Peppol (exit code 1 when something needs attention)';

    public function handle(IntegrationHealth $health): int
    {
        $status = $health();

        if ($this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($status as $area => $values) {
                $this->info(strtoupper($area));
                $rows = [];

                foreach ($values as $key => $value) {
                    if (is_array($value)) {
                        continue;
                    }

                    $rows[] = [$key, is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? '–')];
                }

                $this->table(['Key', 'Value'], $rows);

                foreach ($values['problems'] as $problem) {
                    $this->warn("  ! {$problem}");
                }
            }
        }

        $problems = array_merge($status['banking']['problems'], $status['peppol']['problems']);

        if ($problems === []) {
            $this->comment('All ok.');

            return self::SUCCESS;
        }

        $this->comment(count($problems).' things need attention.');

        return self::FAILURE;
    }
}
