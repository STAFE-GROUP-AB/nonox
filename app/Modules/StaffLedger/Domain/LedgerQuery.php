<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

use App\Modules\StaffLedger\Models\Entry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads the current company's ledger: entries for a period with the
 * corrections that refer to them, shifts, and who is checked in now.
 */
final class LedgerQuery
{
    /** How far back an open shift still counts as someone being on site. */
    public const int PRESENT_LOOKBACK_DAYS = 14;

    /**
     * Entries that happened in the period (with a day's margin, so a shift
     * over midnight is whole) and every correction of them.
     *
     * @return Collection<int, Entry>
     */
    public static function entries(?int $locationId, CarbonImmutable $from, CarbonImmutable $to, ?int $personId = null): Collection
    {
        $entries = Entry::query()
            ->where('type', '<>', EntryType::Correction->value)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->when($personId !== null, fn ($query) => $query->where('person_id', $personId))
            ->whereBetween('occurred_at', [$from->subDay(), $to->addDay()])
            ->with(['user:id,name', 'location:id,name'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        if ($entries->isEmpty()) {
            return $entries;
        }

        $corrections = Entry::query()
            ->where('type', EntryType::Correction->value)
            ->whereIn('corrects_entry_id', $entries->modelKeys())
            ->with(['user:id,name', 'location:id,name'])
            ->orderBy('id')
            ->get();

        return $entries->concat($corrections)->values();
    }

    /**
     * Shifts that overlap the period, oldest first.
     *
     * @return list<Shift>
     */
    public static function shifts(?int $locationId, CarbonImmutable $from, CarbonImmutable $to, ?int $personId = null): array
    {
        $shifts = ShiftBuilder::shifts(self::entries($locationId, $from, $to, $personId));

        return array_values(array_filter($shifts, function (Shift $shift) use ($from, $to): bool {
            $start = $shift->start ?? $shift->end;
            $end = $shift->end ?? CarbonImmutable::now();

            return $start !== null && $start <= $to && $end >= $from;
        }));
    }

    /**
     * Open shifts: who is checked in now.
     *
     * @return list<Shift>
     */
    public static function present(?int $locationId = null): array
    {
        $now = CarbonImmutable::now();
        $shifts = ShiftBuilder::shifts(self::entries($locationId, $now->subDays(self::PRESENT_LOOKBACK_DAYS), $now));

        return array_values(array_filter($shifts, fn (Shift $shift): bool => $shift->isOpen()));
    }

    /** The open shift of a person at a location, if they are checked in. */
    public static function openShift(int $personId, int $locationId): ?Shift
    {
        foreach (self::present($locationId) as $shift) {
            if ($shift->personId === $personId) {
                return $shift;
            }
        }

        return null;
    }
}
