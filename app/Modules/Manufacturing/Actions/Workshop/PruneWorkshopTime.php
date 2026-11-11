<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Workshop;

use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\Manufacturing\Models\WorkshopTimeEntry;
use App\Modules\Manufacturing\Models\WorkshopWaitingFlag;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cuts the workshop terminal's time entries loose from the people who
 * reported them once the retention period is over (GDPR art. 5.1 e),
 * run by `privacy:prune`. The row stays, with its minutes, day, order and
 * operation, since the labour cost it stands for is räkenskapsinformation
 * for the order's manufacturing cost; only the person is removed. The
 * database trigger allows no other change. Archived people with no
 * entries left go too.
 *
 * TODO(legal-verify): the 24 months (docs/gdpr/lagringstider.md). The
 * purpose is checking and correcting reported time and the post-calculation;
 * a shorter period may be enough.
 */
final class PruneWorkshopTime
{
    public const int RETENTION_MONTHS = 24;

    /**
     * @return array{entries: int, people: int, reasons: int}
     */
    public function __invoke(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subMonths(self::RETENTION_MONTHS);

        // Retention runs across every company at once.
        return CompanyScope::bypass(function () use ($cutoff, $now): array {
            $entries = WorkshopTimeEntry::query()->whereNotNull('person_id')->where('entered_at', '<', $cutoff)->update(['person_id' => null]);

            $people = WorkshopPerson::query()
                ->whereNotNull('archived_at')->where('archived_at', '<', $cutoff)
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('workshop_time_entries')->whereColumn('workshop_time_entries.person_id', 'workshop_people.id'))
                ->get()
                ->each(fn (WorkshopPerson $person) => $person->delete())
                ->count();

            // The free text of a waiting flag may have named someone and serves only the waiting time: blank it after a year.
            $reasons = WorkshopWaitingFlag::query()
                ->where('raised_at', '<', $now->subMonths(WaitingFlags::REASON_RETENTION_MONTHS))
                ->where('reason', '<>', WaitingFlags::ERASED)
                ->update(['reason' => WaitingFlags::ERASED]);

            return ['entries' => $entries, 'people' => $people, 'reasons' => $reasons];
        });
    }
}
