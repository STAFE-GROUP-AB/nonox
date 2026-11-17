<?php

declare(strict_types=1);

namespace App\Modules\Planning\Console;

use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftTask;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Removes the tasks on shifts, with their tick-offs, 12 months after the
 * shift ended (ADR-037 gap 4, docs/gdpr/lagringstider.md). A tick-off is
 * personal data about work performance (who did what, and when): it is kept
 * only for that time, used for nothing else and never summed up per
 * employee. The period is a proposed default and the owner's decision.
 *
 * TODO(legal-verify): the period; whether a shorter one is needed for ticks
 * than for the shifts themselves.
 */
class PruneTasksCommand extends Command
{
    public const int KEEP_MONTHS = 12;

    protected $signature = 'planning:prune-tasks';

    protected $description = 'Remove shift tasks and tick-offs a year after the shift ended';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::now()->subMonths(self::KEEP_MONTHS)->utc()->format('Y-m-d H:i:sP');

        // Retention runs across every company: one delete, no company's data is read.
        $removed = CompanyScope::bypass(fn (): int => ShiftTask::query()
            ->whereIn('shift_id', Shift::query()->select('id')->where('ends_at', '<', $cutoff))
            ->delete());

        $this->comment("Removed {$removed} shift tasks older than ".self::KEEP_MONTHS.' months.');

        return self::SUCCESS;
    }
}
