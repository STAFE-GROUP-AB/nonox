<?php

declare(strict_types=1);

namespace App\Modules\Planning\Console;

use App\Modules\Planning\Models\Proposal;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Deletes schedule proposals older than 90 days, with their items (ADR-037
 * amendment, gap 3), in every company whether or not Planering is on now:
 * the score parts are personal data about how work is shared out and have
 * no use after the schedule is set. Touches the proposal tables only.
 */
class PruneProposalsCommand extends Command
{
    protected $signature = 'planning:prune-proposals';

    protected $description = 'Gallrar schemaförslag som är äldre än 90 dagar.';

    public function handle(): int
    {
        $before = CarbonImmutable::now()->subDays(Proposal::RETENTION_DAYS);

        $this->info("Deleting schedule proposals made before {$before->toDateString()}...");

        // Retention runs across companies: only rows past their retention are touched, never read.
        $deleted = CompanyScope::bypass(fn (): int => (int) Proposal::query()->where('created_at', '<', $before)->delete());

        $this->comment("Deleted {$deleted} schedule proposals.");

        return self::SUCCESS;
    }
}
