<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Hq\ReleaseWaitlistProspect;
use App\Domain\Waitlist\WaitlistStatus;
use App\Models\WaitlistEntry;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deletes waiting-list entries whose purpose is over (GDPR art. 5.1 e,
 * ADR-038): rejected entries some days after the decision, and entries
 * that have waited, or have been approved, longer than the retention
 * period. The user an approval created is not touched. The CRM prospect
 * an entry became in the operator company is released first
 * (ReleaseWaitlistProspect, ADR-044).
 *
 * TODO(legal-verify): the periods (docs/gdpr/lagringstider.md).
 */
class PruneWaitlistCommand extends Command
{
    /** Days a rejected entry is kept after the decision, so a mistake can be noticed. */
    public const int REJECTED_DAYS = 30;

    protected $signature = 'waitlist:prune';

    protected $description = 'Gallrar kön: nekade anmälningar och anmälningar äldre än lagringstiden.';

    public function handle(ReleaseWaitlistProspect $release): int
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

        // The CRM prospect each entry became goes first (ADR-044); an entry whose prospect cannot be released waits for the next night.
        $released = [];

        foreach ($ids as $id) {
            try {
                DB::transaction(fn () => $release($id));
                $released[] = $id;
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("Anmälan {$id}: prospektet i CRM kunde inte gallras, försöker igen nästa natt.");
            }
        }

        if ($released !== []) {
            DB::transaction(function () use ($released, $months): void {
                WaitlistEntry::forgetNotices($released);
                WaitlistEntry::query()->whereIn('id', $released)->delete();

                Audit::instance('waitlist.pruned', null, ['count' => count($released), 'retention_months' => $months, 'rejected_days' => self::REJECTED_DAYS]);
            });
        }

        $this->comment('Klart.');

        return self::SUCCESS;
    }
}
