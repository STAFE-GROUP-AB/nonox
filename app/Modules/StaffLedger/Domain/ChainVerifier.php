<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

use App\Models\AuditLog;
use App\Models\Company;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Support\Tenancy\CurrentCompany;

/**
 * Recomputes every location's hash chain (`personalliggare:verify`), the
 * staff ledger's counterpart to LedgerVerifier. Entries are appended under a
 * lock on the location, so the chain follows the id order: every entry must
 * link to the one before it and hash to what is stored, the chain must start
 * at the location's anchor (empty, or the last entry removed by the
 * retention prune, as recorded in the audit log) and end at the stored head.
 */
final class ChainVerifier
{
    /**
     * @return array{ok: bool, entries: int, problems: list<string>}
     */
    public function verify(Company $company): array
    {
        return CurrentCompany::run($company, function () use ($company): array {
            $problems = [];
            $count = 0;

            foreach (Location::query()->orderBy('id')->get() as $location) {
                $previous = $location->chain_start_hash;
                $label = $location->name;

                $pruned = AuditLog::query()->forCompany($company)
                    ->where('action', 'staff_ledger.pruned')
                    ->where('subject_type', $location->getMorphClass())
                    ->where('subject_id', (string) $location->id)
                    ->latest('id')
                    ->value('payload');

                $recordedAnchor = is_array($pruned) ? ($pruned['anchor'] ?? null) : null;

                if ($recordedAnchor !== $location->chain_start_hash) {
                    $problems[] = "Lokal {$label}: kedjans början stämmer inte med den senaste gallringen.";
                }

                foreach (Entry::query()->where('location_id', $location->id)->lazyById(500) as $entry) {
                    $count++;

                    if ($entry->previous_hash !== $previous) {
                        $problems[] = "Lokal {$label}: kedjan är bruten före post {$entry->id}. En post kan ha tagits bort.";
                    }

                    if (EntryHash::of($entry, $entry->previous_hash) !== $entry->hash) {
                        $problems[] = "Lokal {$label}: post {$entry->id} har ändrats efter att den skrevs.";
                    }

                    $previous = $entry->hash;
                }

                if ($previous !== $location->ledger_head_hash) {
                    $problems[] = "Lokal {$label}: kedjans sista länk stämmer inte med den sparade. En post kan ha tagits bort.";
                }
            }

            return ['ok' => $problems === [], 'entries' => $count, 'problems' => $problems];
        });
    }
}
