<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Waitlist\WaitlistStatus;
use App\Models\WaitlistEntry;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes waiting-list entries whose purpose is over (GDPR art. 5.1 e,
 * ADR-038): rejected entries some days after the decision, and entries
 * that have waited, or have been approved, longer than the retention
 * period. The user an approval created is not touched.
 *
 * TODO(legal-verify): the periods (docs/gdpr/lagringstider.md).
 */
class PruneWaitlistCommand extends Command
{
    /** Days a rejected entry is kept after the decision, so a mistake can be noticed. */
    public const int REJECTED_DAYS = 30;

    protected $signature = 'waitlist:prune';

    protected $description = 'Gallrar kön: nekade anmälningar och anmälningar äldre än lagringstiden.';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $months = (int) config('nonox.waitlist.retention_months', 12);

        $ids = WaitlistEntry::query()
            ->where(fn ($query) => $query
                ->where(fn ($rejected) => $rejected->where('status', WaitlistStatus::Rejected->value)->where('decided_at', '<', $now->subDays(self::REJECTED_DAYS)))
                ->orWhere(fn ($waiting) => $waiting->whereIn('status', [WaitlistStatus::Pending->value, WaitlistStatus::Parked->value])->where('created_at', '<', $now->subMonths($months)))
                ->orWhere(fn ($approved) => $approved->where('status', WaitlistStatus::Approved->value)->where('decided_at', '<', $now->subMonths($months))))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $this->info('Gallrar '.count($ids).' anmälningar i kön …');

        if ($ids !== []) {
            DB::transaction(function () use ($ids, $months): void {
                WaitlistEntry::forgetNotices($ids);
                WaitlistEntry::query()->whereIn('id', $ids)->delete();

                Audit::instance('waitlist.pruned', null, ['count' => count($ids), 'retention_months' => $months, 'rejected_days' => self::REJECTED_DAYS]);
            });
        }

        $this->comment('Klart.');

        return self::SUCCESS;
    }
}
