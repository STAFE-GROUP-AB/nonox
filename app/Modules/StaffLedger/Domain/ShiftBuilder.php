<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

use App\Modules\StaffLedger\Models\Entry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads shifts out of ledger entries. Corrections are applied on the way:
 * a voided entry is left out, a moved one counts at its corrected time. The
 * entries themselves are never changed.
 */
final class ShiftBuilder
{
    /**
     * The time each entry counts at, by entry id; voided entries are absent.
     *
     * @param  iterable<Entry>  $entries
     * @return array<int, CarbonImmutable>
     */
    public static function effectiveTimes(iterable $entries): array
    {
        $times = [];
        $corrections = [];

        foreach ($entries as $entry) {
            if ($entry->type === EntryType::Correction) {
                $corrections[] = $entry;

                continue;
            }

            $times[$entry->id] = $entry->occurred_at;
        }

        usort($corrections, fn (Entry $a, Entry $b): int => $a->id <=> $b->id);

        foreach ($corrections as $correction) {
            $target = $correction->corrects_entry_id;

            if ($target === null || ! array_key_exists($target, $times)) {
                continue;
            }

            if ($correction->correction === CorrectionKind::Void) {
                unset($times[$target]);
            } else {
                $times[$target] = $correction->occurred_at;
            }
        }

        return $times;
    }

    /**
     * @param  Collection<int, Entry>  $entries  every entry of the period, corrections included
     * @return list<Shift>
     */
    public static function shifts(Collection $entries): array
    {
        $times = self::effectiveTimes($entries);
        $shifts = [];

        $events = $entries
            ->filter(fn (Entry $entry): bool => isset($times[$entry->id]) && $entry->effectiveDirection() !== null)
            ->sortBy(fn (Entry $entry): string => $times[$entry->id]->format('Y-m-d H:i:s').sprintf('%012d', $entry->id))
            ->groupBy(fn (Entry $entry): string => $entry->location_id.':'.($entry->person_id ?? 'x'.$entry->id));

        foreach ($events as $group) {
            $open = null;

            foreach ($group as $entry) {
                $time = $times[$entry->id];

                if ($entry->effectiveDirection() === Direction::In) {
                    if ($open instanceof Entry) {
                        $shifts[] = self::shift($open, $times[$open->id], null, null);
                    }

                    $open = $entry;

                    continue;
                }

                $shifts[] = $open instanceof Entry
                    ? self::shift($open, $times[$open->id], $entry, $time)
                    : self::shift($entry, null, $entry, $time);
                $open = null;
            }

            if ($open instanceof Entry) {
                $shifts[] = self::shift($open, $times[$open->id], null, null);
            }
        }

        usort($shifts, fn (Shift $a, Shift $b): int => ($a->start ?? $a->end) <=> ($b->start ?? $b->end));

        return $shifts;
    }

    private static function shift(Entry $person, ?CarbonImmutable $start, ?Entry $endEntry, ?CarbonImmutable $end): Shift
    {
        return new Shift(
            locationId: $person->location_id,
            personId: $person->person_id,
            personName: $person->person_name,
            personalNumber: $person->personal_number,
            employerName: $person->employer_name,
            employerOrgNumber: $person->employer_org_number,
            start: $start,
            end: $end,
            startEntry: $start === null ? null : $person,
            endEntry: $endEntry,
        );
    }
}
