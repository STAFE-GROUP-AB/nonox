<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Actions;

use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Domain\EntryHash;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\StaffLedgerModule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Appends one entry to a location's ledger: the location row is locked, the
 * entry gets its id from the sequence before it is written, its hash links
 * to the location's head, and the head moves to it. All or nothing. Every
 * way of writing to the ledger goes through here.
 */
final class AppendEntry
{
    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @param  array<string, mixed>  $attributes  type, direction, method and so on; occurred_at as CarbonImmutable
     */
    public function __invoke(Location $location, ?Person $person, array $attributes): Entry
    {
        $this->modules->ensureEnabled(StaffLedgerModule::KEY);

        return DB::transaction(function () use ($location, $person, $attributes): Entry {
            /** @var Location $locked */
            $locked = Location::query()->whereKey($location->id)->lockForUpdate()->firstOrFail();

            $id = (int) DB::scalar("select nextval(pg_get_serial_sequence('staff_ledger_entries', 'id'))");
            /** @var CarbonImmutable $occurredAt */
            $occurredAt = $attributes['occurred_at'];
            $snapshot = $person === null ? [] : [
                'person_id' => $person->id,
                'person_name' => $person->name(),
                'personal_number' => $person->personal_number,
                'employer_name' => $person->employer_name,
                'employer_org_number' => $person->employer_org_number,
            ];

            $entry = new Entry([
                'uuid' => (string) Str::uuid(),
                'flags' => [],
                ...$snapshot,
                ...$attributes,
                'company_id' => $locked->company_id,
                'location_id' => $locked->id,
                'occurred_at' => EntryHash::storable($occurredAt),
                'received_at' => EntryHash::storable(CarbonImmutable::now()),
                'previous_hash' => $locked->ledger_head_hash,
            ]);
            $entry->id = $id;
            $entry->hash = EntryHash::of($entry, $locked->ledger_head_hash);
            $entry->save();

            $locked->forceFill(['ledger_head_hash' => $entry->hash])->save();
            $location->setRawAttributes($locked->getAttributes(), true);

            return $entry;
        });
    }
}
