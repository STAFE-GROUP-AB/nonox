<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Actions;

use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Removes staff ledger data whose retention period is over (GDPR art. 5.1 e),
 * run by `privacy:prune`. Entries go from the oldest end of each location's
 * chain only: the location's chain anchor moves to the last removed hash and
 * the prune is written to the audit log, so `personalliggare:verify` still
 * proves the rest of the chain. The append-only trigger lets these deletes
 * through only with a flag set for this transaction. Archived people with no
 * entries left are removed with their photo.
 *
 * TODO(legal-verify): the retention period. Assumed: the ledger is kept for
 * two years after the end of the calendar year it was kept (39 kap.
 * skatteförfarandelagen / prop. 2025/26:282); see docs/gdpr/lagringstider.md.
 */
final class PruneStaffLedger
{
    public const int RETENTION_YEARS_AFTER_YEAR_END = 2;

    /**
     * @return array{entries: int, people: int}
     */
    public function __invoke(?CarbonImmutable $now = null): array
    {
        $cutoff = self::cutoff($now ?? CarbonImmutable::now());

        // Retention runs across every company; each location is pruned in its own transaction.
        return CompanyScope::bypass(function () use ($cutoff): array {
            $entries = 0;

            foreach (Location::query()->orderBy('id')->get() as $location) {
                $entries += $this->pruneLocation($location, $cutoff);
            }

            $people = 0;

            Person::query()
                ->whereNotNull('archived_at')
                ->where('archived_at', '<', $cutoff)
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('staff_ledger_entries')->whereColumn('staff_ledger_entries.person_id', 'staff_ledger_people.id'))
                ->each(function (Person $person) use (&$people): void {
                    app(PersonCredentials::class)->deletePhotoFile($person);
                    $person->delete();
                    $people++;
                });

            return ['entries' => $entries, 'people' => $people];
        });
    }

    /** The oldest time still kept: 1 January two years before this year. */
    public static function cutoff(CarbonImmutable $now): CarbonImmutable
    {
        return $now->startOfYear()->subYears(self::RETENTION_YEARS_AFTER_YEAR_END);
    }

    private function pruneLocation(Location $location, CarbonImmutable $cutoff): int
    {
        return DB::transaction(function () use ($location, $cutoff): int {
            /** @var Location $locked */
            $locked = Location::query()->whereKey($location->id)->lockForUpdate()->firstOrFail();

            $firstKept = Entry::query()->where('location_id', $locked->id)
                ->where(fn ($query) => $query->where('occurred_at', '>=', $cutoff)->orWhere('received_at', '>=', $cutoff))
                ->min('id');

            $last = Entry::query()->where('location_id', $locked->id)
                ->when($firstKept !== null, fn ($query) => $query->where('id', '<', $firstKept))
                ->orderByDesc('id')
                ->first();

            if (! $last instanceof Entry) {
                return 0;
            }

            DB::statement("select set_config('nonox.staff_ledger_prune', 'on', true)");
            $count = DB::table('staff_ledger_entries')->where('location_id', $locked->id)->where('id', '<=', $last->id)->delete();
            DB::statement("select set_config('nonox.staff_ledger_prune', 'off', true)");

            $locked->forceFill(['chain_start_hash' => $last->hash])->save();

            Audit::record('staff_ledger.pruned', $locked, ['anchor' => $last->hash, 'entries' => $count, 'cutoff' => $cutoff->toDateString()], $locked->company_id);

            return $count;
        });
    }
}
