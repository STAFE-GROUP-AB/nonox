<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Actions;

use App\Models\Company;
use App\Models\FiscalYear;
use App\Modules\StaffLedger\Domain\Retention;
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
 * The retention follows the company's fiscal year (Retention): an entry is
 * kept until two years after the end of the calendar year in which the
 * fiscal year containing its date ended (9 kap. 5 § tredje stycket
 * skatteförfarandeförordningen). Both the time of the entry and the time the
 * server received it must be past retention. Walking a chain stops at the
 * first entry that is kept, so an entry is never removed while an older one
 * in the same chain stays. Enligt AI-utredning 2026-11-02, juristgranskning
 * återstår; see docs/gdpr/lagringstider.md.
 */
final class PruneStaffLedger
{
    /**
     * @return array{entries: int, people: int}
     */
    public function __invoke(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $earliestKept = Retention::earliestKept($now);

        // Retention runs across every company; each location is pruned in its own transaction.
        return CompanyScope::bypass(function () use ($now, $earliestKept): array {
            $locations = Location::query()->orderBy('id')->get();
            $people = Person::query()
                ->whereNotNull('archived_at')
                ->where('archived_at', '<', $earliestKept)
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('staff_ledger_entries')->whereColumn('staff_ledger_entries.person_id', 'staff_ledger_people.id'))
                ->get();

            $companyIds = $locations->map(fn (Location $location): int => $location->company_id)
                ->merge($people->map(fn (Person $person): int => $person->company_id))
                ->unique()
                ->values()
                ->all();
            $retention = $this->retentionPerCompany($companyIds);

            $entries = 0;

            foreach ($locations as $location) {
                $entries += $this->pruneLocation($location, $retention[$location->company_id], $now, $earliestKept);
            }

            $removed = 0;

            foreach ($people as $person) {
                /** @var CarbonImmutable $archivedAt */
                $archivedAt = $person->archived_at;

                if (! $retention[$person->company_id]->expired($archivedAt, $now)) {
                    continue;
                }

                app(PersonCredentials::class)->deletePhotoFile($person);
                $person->delete();
                $removed++;
            }

            return ['entries' => $entries, 'people' => $removed];
        });
    }

    /**
     * Each company's fiscal years, loaded once for all companies.
     *
     * @param  array<int, int>  $companyIds
     * @return array<int, Retention>
     */
    private function retentionPerCompany(array $companyIds): array
    {
        $years = FiscalYear::query()->whereIn('company_id', $companyIds)->orderBy('start_date')->get()->groupBy('company_id');
        $startMonths = Company::query()->whereIn('id', $companyIds)->pluck('fiscal_year_start_month', 'id');
        $retention = [];

        foreach ($companyIds as $id) {
            $month = $startMonths[$id] ?? null;
            $retention[$id] = new Retention($years->get($id) ?? collect(), $month === null ? null : (int) $month);
        }

        return $retention;
    }

    private function pruneLocation(Location $location, Retention $retention, CarbonImmutable $now, CarbonImmutable $earliestKept): int
    {
        return DB::transaction(function () use ($location, $retention, $now, $earliestKept): int {
            /** @var Location $locked */
            $locked = Location::query()->whereKey($location->id)->lockForUpdate()->firstOrFail();

            // Nothing dated on or after $earliestKept can be expired, so the walk never reads past it.
            $firstPossiblyKept = Entry::query()->where('location_id', $locked->id)
                ->where(fn ($query) => $query->where('occurred_at', '>=', $earliestKept)->orWhere('received_at', '>=', $earliestKept))
                ->min('id');

            $last = null;
            $candidates = Entry::query()->where('location_id', $locked->id)
                ->when($firstPossiblyKept !== null, fn ($query) => $query->where('id', '<', $firstPossiblyKept))
                ->select(['id', 'occurred_at', 'received_at', 'hash']);

            foreach ($candidates->lazyById(500) as $entry) {
                if (! $retention->expired($entry->occurred_at, $now) || ! $retention->expired($entry->received_at, $now)) {
                    break;
                }

                $last = $entry;
            }

            if (! $last instanceof Entry) {
                return 0;
            }

            DB::statement("select set_config('nonox.staff_ledger_prune', 'on', true)");
            $count = DB::table('staff_ledger_entries')->where('location_id', $locked->id)->where('id', '<=', $last->id)->delete();
            DB::statement("select set_config('nonox.staff_ledger_prune', 'off', true)");

            $locked->forceFill(['chain_start_hash' => $last->hash])->save();

            Audit::record('staff_ledger.pruned', $locked, [
                'anchor' => $last->hash,
                'entries' => $count,
                'last_removed_kept_until' => $retention->keptUntil($last->occurred_at)?->toDateString(),
                'rule' => '9 kap. 5 § 3 st. SFF',
            ], $locked->company_id);

            return $count;
        });
    }
}
