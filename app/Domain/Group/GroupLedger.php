<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Models\CorporateGroup;
use App\Models\GroupEntry;

/**
 * Checks a shared group's ledger (ADR-081 E25), as ledger:verify checks a
 * company's: the numbers run 1, 2, 3 … without a gap, each entry names the
 * previous entry's hash, each hash still matches what is stored, and each
 * entry balances. Two queries, whatever the number of entries.
 */
final class GroupLedger
{
    /**
     * @return array{entries: int, intact: bool, broken: list<int>} broken: the numbers of entries that fail
     */
    public static function verify(CorporateGroup $group): array
    {
        $entries = GroupEntry::query()->where('corporate_group_id', $group->id)->with('rows')->orderBy('number')->get();
        $broken = [];
        $previous = null;
        $expected = 1;

        foreach ($entries as $entry) {
            $debit = $entry->rows->sum('debit_ore');
            $ok = $entry->number === $expected
                && $entry->previous_hash === $previous
                && hash_equals($entry->hash, $entry->recomputedHash())
                && $entry->rows->count() === $entry->rows_count
                && $debit === $entry->rows->sum('credit_ore')
                && $debit === $entry->total_ore;

            if (! $ok) {
                $broken[] = $entry->number;
            }

            $previous = $entry->hash;
            $expected = $entry->number + 1;
        }

        return ['entries' => $entries->count(), 'intact' => $broken === [], 'broken' => $broken];
    }
}
