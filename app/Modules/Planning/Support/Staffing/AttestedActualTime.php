<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support\Staffing;

use App\Models\Company;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\Support\LocalTimestamp;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Actual time from attested days (Tid att attestera, PLANNING_MODULE.md §4):
 * what the manager approved, per employee and day, with the actual
 * intervals frozen at attestation so the cost is estimated on the real
 * times. Covers only the days that have attested rows; the staff ledger
 * answers for the others. One query.
 */
final class AttestedActualTime implements ActualTime
{
    public function key(): string
    {
        return 'attested';
    }

    public function between(Company $company, CarbonImmutable $from, CarbonImmutable $to): ActualPeriod
    {
        $rows = Attestation::query()
            ->whereIn('status', [Attestation::ATTESTED, Attestation::SENT])
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('date')
            ->get(['id', 'employee_id', 'date', 'actual_minutes', 'details']);

        $work = [];
        $days = [];

        foreach ($rows as $row) {
            $day = $row->date->toDateString();
            $days[$day] = true;
            $intervals = self::intervals($row, $day);

            if ($intervals === []) {
                $work[] = new ActualWork($day, $row->employee_id, $row->actual_minutes);

                continue;
            }

            array_push($work, ...$intervals);
        }

        return new ActualPeriod(array_keys($days), $work);
    }

    /**
     * The attested actual intervals of the day with start and end.
     *
     * @return list<ActualWork>
     */
    private static function intervals(Attestation $row, string $day): array
    {
        $work = [];

        foreach ((array) ($row->details['actual'] ?? []) as $interval) {
            $interval = (array) $interval;

            if (! is_string($interval['start'] ?? null) || ! is_string($interval['end'] ?? null)) {
                return [];
            }

            try {
                $start = CarbonImmutable::parse($interval['start'])->setTimezone(LocalTimestamp::ZONE);
                $end = CarbonImmutable::parse($interval['end'])->setTimezone(LocalTimestamp::ZONE);
            } catch (Throwable) {
                return [];
            }

            $break = $interval['break_minutes'];
            $work[] = new ActualWork($day, $row->employee_id, max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60) - $break), $start, $end, $break);
        }

        return $work;
    }
}
